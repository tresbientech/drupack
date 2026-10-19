"""The Mercure hub a site's contract turns on: a request publishes through mercure_publish(),
a subscriber that accepts gzip and holds a Mercure 1.0 access token signed with the Site
data secret, in a header or a browser's cookie, receives it as it is published, a reconnect replays the history, a publisher token posts over HTTP, `drush` sees the hub's
variables once a start created the secrets, and a site without the key serves no hub and
writes no secret.
"""

import base64
import hashlib
import hmac
import http.client
import json
import os
import ssl
import stat
import time
import unittest
import zlib
from urllib.error import HTTPError
from urllib.parse import urlencode

import harness
from tls_cases import trusted_environment, write_root

HUB = "/.well-known/mercure"
TOPIC = "drupack/probe"
SECRETS = ("mercure_publisher_secret", "mercure_subscriber_secret")
COOKIE = "mercure_access_token"


def signed(secret, header, claims):
    """An HS256 JSON Web Token with header and claims, signed with secret."""
    def encode(data):
        return base64.urlsafe_b64encode(data).rstrip(b"=")
    body = encode(json.dumps(dict(header, alg="HS256")).encode()) + b"." + encode(json.dumps(claims).encode())
    return (body + b"." + encode(hmac.new(secret.encode(), body, hashlib.sha256).digest())).decode()


def token(secret, port, action, scheme="http"):
    """A Mercure 1.0 access token granting action on TOPIC, whose iss and aud name the hub."""
    hub = f"{scheme}://127.0.0.1:{port}{HUB}"
    return signed(secret, {"typ": "at+jwt"}, {
        "iss": hub, "aud": hub, "exp": int(time.time()) + 600,
        "authorization_details": [{"type": "https://mercure.rocks/authorization-detail",
                                   "actions": [action], "topics": [{"match": TOPIC}]}],
    })


class Stream:
    """One subscription to TOPIC that accepts gzip, read as it arrives. The token goes in the
    Authorization header, or in the cookie a browser's EventSource sends when cookie is set."""

    def __init__(self, port, bearer, last_event_id=None, cookie=False, context=None):
        timeout = harness.WAITS["http_request"].seconds
        if context is None:
            self.connection = http.client.HTTPConnection("localhost", port, timeout=timeout)
        else:
            self.connection = http.client.HTTPSConnection("localhost", port, timeout=timeout, context=context)
        headers = {"Accept": "text/event-stream", "Accept-Encoding": "gzip"}
        if cookie:
            headers["Cookie"] = f"{COOKIE}={bearer}"
        else:
            headers["Authorization"] = f"Bearer {bearer}"
        if last_event_id is not None:
            headers["Last-Event-ID"] = last_event_id
        self.connection.request("GET", f"{HUB}?match={TOPIC}", headers=headers)
        self.response = self.connection.getresponse()
        # Caddy's encode skips a response whose first write falls under its minimum length,
        # and the hub opens every stream with a two-byte comment line.
        gzip = self.response.getheader("Content-Encoding") == "gzip"
        self.decode = zlib.decompressobj(16 + zlib.MAX_WBITS).decompress if gzip else bytes
        self.text = ""

    def read_until(self, needle, seconds=15):
        """Reads until the decoded stream holds needle, and fails after seconds."""
        deadline = time.monotonic() + seconds
        while needle not in self.text:
            if time.monotonic() > deadline:
                raise AssertionError(f"the stream never carried {needle!r}: {self.text!r}")
            chunk = self.response.read1(4096)
            if not chunk:
                raise AssertionError(f"the stream ended before {needle!r}: {self.text!r}")
            self.text += self.decode(chunk).decode()

    def close(self):
        self.connection.close()


class MercureHub(harness.ConformanceCase):
    PLATFORMS = (harness.LINUX, harness.MACOS, harness.WINDOWS)

    @classmethod
    def setUpClass(cls):
        if not harness.SITE["mercure"]:
            raise unittest.SkipTest("the site's contract leaves mercure off")
        cls.data = cls.class_dir / "data"
        cls.site = harness.Site(harness.BINARY, cls.class_dir / "site")
        cls.site.start(cls.data)

    @classmethod
    def tearDownClass(cls):
        cls.site.stop()

    def secret(self, name):
        return (self.data / name).read_text()

    def subscriber_token(self):
        return token(self.secret("mercure_subscriber_secret"), self.site.port, "subscribe")

    def publish(self, data):
        """Publishes data through the probe, which calls mercure_publish(), and returns the update's id."""
        status, _, body = self.site.fetch(f"/mercure-probe.php?data={data}")
        self.assertEqual(status, 200, body)
        return body

    def test_a_subscriber_receives_what_a_request_publishes(self):
        stream = Stream(self.site.port, self.subscriber_token())
        try:
            self.assertEqual(stream.response.status, 200)
            # The hub opens every stream with a comment line, so the subscription exists before the publish.
            stream.read_until(":")
            update = self.publish("live")
            stream.read_until(f"id: {update}\ndata: live\n")
        finally:
            stream.close()

    def test_a_browser_subscribes_with_the_token_in_a_cookie(self):
        stream = Stream(self.site.port, self.subscriber_token(), cookie=True)
        try:
            self.assertEqual(stream.response.status, 200)
            stream.read_until(":")
            update = self.publish("cookie")
            stream.read_until(f"id: {update}\ndata: cookie\n")
        finally:
            stream.close()

    def test_a_reconnect_replays_what_it_missed(self):
        seen = self.publish("seen")
        self.publish("missed")
        stream = Stream(self.site.port, self.subscriber_token(), last_event_id=seen)
        try:
            stream.read_until("data: missed\n")
            self.assertNotIn("data: seen\n", stream.text)
        finally:
            stream.close()

    def test_a_subscriber_without_a_valid_token_is_refused(self):
        secret = self.secret("mercure_subscriber_secret")
        refused = [
            ("no token", None),
            ("another key", token("another key", self.site.port, "subscribe")),
            ("a Mercure 0.x token", signed(secret, {"typ": "JWT"}, {"mercure": {"subscribe": [TOPIC]}})),
        ]
        for name, bearer in refused:
            with self.subTest(name):
                connection = http.client.HTTPConnection("localhost", self.site.port, timeout=harness.WAITS["http_request"].seconds)
                try:
                    headers = {} if bearer is None else {"Authorization": f"Bearer {bearer}"}
                    connection.request("GET", f"{HUB}?match={TOPIC}", headers=headers)
                    self.assertEqual(connection.getresponse().status, 401)
                finally:
                    connection.close()

    def drush_environment(self):
        """The hub variables a `drush` command's PHP sees, empty when unset."""
        code = "echo json_encode(array_map('getenv', ['DRUPACK_MERCURE_URL', 'DRUPACK_MERCURE_PUBLISHER_SECRET', 'DRUPACK_MERCURE_SUBSCRIBER_SECRET']));"
        result = harness.run([str(harness.BINARY), "drush", "--data-dir", str(self.data), "php:eval", code],
                             cwd=self.class_dir, capture_output=True, text=True, timeout=harness.WAITS["drush"].seconds)
        self.assertEqual(result.returncode, 0, result.stderr)
        return json.loads(result.stdout)

    def test_an_http_publish_reaches_a_subscriber(self):
        stream = Stream(self.site.port, self.subscriber_token())
        connection = http.client.HTTPConnection("localhost", self.site.port, timeout=harness.WAITS["http_request"].seconds)
        try:
            stream.read_until(":")
            bearer = token(self.secret("mercure_publisher_secret"), self.site.port, "publish")
            connection.request("POST", HUB, body=urlencode({"topic": TOPIC, "data": "posted", "private": "on"}),
                               headers={"Authorization": f"Bearer {bearer}", "Content-Type": "application/x-www-form-urlencoded"})
            response = connection.getresponse()
            update = response.read().decode()
            self.assertEqual(response.status, 200, update)
            stream.read_until(f"id: {update}\ndata: posted\n")
        finally:
            connection.close()
            stream.close()

    def test_drush_sees_the_hub_variables(self):
        self.assertEqual(self.drush_environment(), [
            f"http://127.0.0.1:{self.site.port}{HUB}",
            self.secret("mercure_publisher_secret"),
            self.secret("mercure_subscriber_secret"),
        ])

    def test_drush_creates_no_secret(self):
        for name in SECRETS:
            os.replace(self.data / name, self.class_dir / name)
        try:
            self.assertEqual(self.drush_environment(), [False, False, False])
            for name in SECRETS:
                self.assertFalse((self.data / name).exists(), name)
        finally:
            for name in SECRETS:
                os.replace(self.class_dir / name, self.data / name)

    def test_the_secrets_stay_private_and_survive_a_restart(self):
        before = {name: self.secret(name) for name in SECRETS}
        for name, value in before.items():
            self.assertRegex(value, r"^[0-9a-f]{64}$", name)
            if harness.current_platform() != harness.WINDOWS:
                self.assertEqual(stat.S_IMODE(os.stat(self.data / name).st_mode), 0o600, name)
        self.assertTrue((self.data / "mercure.db").is_file())
        self.site.stop()
        self.site.start(self.data)
        self.assertEqual({name: self.secret(name) for name in SECRETS}, before)


class MercureHubHttps(harness.ConformanceCase):
    """The hub of a site serving HTTPS from a trusted local root names https, and a
    subscriber over TLS receives what a request publishes."""

    PLATFORMS = (harness.LINUX,)
    TOOLS = ("openssl",)

    @classmethod
    def setUpClass(cls):
        if not harness.SITE["mercure"]:
            raise unittest.SkipTest("the site's contract leaves mercure off")
        root = write_root(cls.class_dir / "tls")
        cls.context = ssl.create_default_context(cafile=str(root))
        cls.data = cls.class_dir / "data"
        cls.site = harness.Site(harness.BINARY, cls.class_dir / "site", root=root)
        cls.site.start(cls.data, env=trusted_environment(root.parent, root))

    @classmethod
    def tearDownClass(cls):
        cls.site.stop()

    def test_drush_names_the_https_hub(self):
        code = "echo getenv('DRUPACK_MERCURE_URL');"
        result = harness.run([str(harness.BINARY), "drush", "--data-dir", str(self.data), "php:eval", code],
                             cwd=self.class_dir, capture_output=True, text=True, timeout=harness.WAITS["drush"].seconds)
        self.assertEqual(result.stdout.strip(), f"https://127.0.0.1:{self.site.port}{HUB}", result.stderr)

    def test_a_subscriber_over_https_receives_what_a_request_publishes(self):
        secret = (self.data / "mercure_subscriber_secret").read_text()
        stream = Stream(self.site.port, token(secret, self.site.port, "subscribe", "https"), context=self.context)
        try:
            self.assertEqual(stream.response.status, 200)
            stream.read_until(":")
            status, _, update = self.site.fetch("/mercure-probe.php?data=secure")
            self.assertEqual(status, 200, update)
            stream.read_until(f"id: {update}\ndata: secure\n")
        finally:
            stream.close()


class MercureHubOff(harness.ConformanceCase):
    PLATFORMS = (harness.LINUX, harness.MACOS, harness.WINDOWS)

    @classmethod
    def setUpClass(cls):
        if harness.SITE["mercure"]:
            raise unittest.SkipTest("the site's contract turns mercure on")

    def test_a_site_without_the_key_serves_no_hub(self):
        data = self.case_dir / "data"
        site = harness.Site(harness.BINARY, self.case_dir / "site")
        site.start(data)
        try:
            with self.assertRaises(HTTPError) as refused:
                site.fetch(f"{HUB}?match={TOPIC}")
            self.assertEqual(refused.exception.code, 404)
        finally:
            site.stop()
        for name in SECRETS:
            self.assertFalse((data / name).exists(), name)
