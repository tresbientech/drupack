"""The Mercure hub a site's contract turns on: a request publishes through mercure_publish(),
a subscriber that accepts gzip and holds a token signed with the Site data secret receives
it as it is published, a reconnect replays the history, and a site without the key serves
no hub and writes no secret.
"""

import base64
import hashlib
import hmac
import http.client
import json
import os
import stat
import time
import unittest
import zlib
from urllib.error import HTTPError

import harness

HUB = "/.well-known/mercure"
TOPIC = "drupack/probe"
SECRETS = ("mercure_publisher_secret", "mercure_subscriber_secret")


def token(secret, claims):
    """An HS256 JSON Web Token over claims, signed with secret."""
    def encode(data):
        return base64.urlsafe_b64encode(data).rstrip(b"=")
    signed = encode(json.dumps({"alg": "HS256", "typ": "JWT"}).encode()) + b"." + encode(json.dumps(claims).encode())
    return (signed + b"." + encode(hmac.new(secret.encode(), signed, hashlib.sha256).digest())).decode()


class Stream:
    """One subscription to TOPIC that accepts gzip, read as it arrives."""

    def __init__(self, port, bearer, last_event_id=None):
        self.connection = http.client.HTTPConnection("localhost", port, timeout=harness.WAITS["http_request"].seconds)
        headers = {"Accept": "text/event-stream", "Accept-Encoding": "gzip", "Authorization": f"Bearer {bearer}"}
        if last_event_id is not None:
            headers["Last-Event-ID"] = last_event_id
        self.connection.request("GET", f"{HUB}?topic={TOPIC}", headers=headers)
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
        return token(self.secret("mercure_subscriber_secret"), {"mercure": {"subscribe": [TOPIC]}})

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
        for name, bearer in [("no token", None), ("another key", token("another key", {"mercure": {"subscribe": [TOPIC]}}))]:
            with self.subTest(name):
                connection = http.client.HTTPConnection("localhost", self.site.port, timeout=harness.WAITS["http_request"].seconds)
                try:
                    headers = {} if bearer is None else {"Authorization": f"Bearer {bearer}"}
                    connection.request("GET", f"{HUB}?topic={TOPIC}", headers=headers)
                    self.assertEqual(connection.getresponse().status, 401)
                finally:
                    connection.close()

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
                site.fetch(f"{HUB}?topic={TOPIC}")
            self.assertEqual(refused.exception.code, 404)
        finally:
            site.stop()
        for name in SECRETS:
            self.assertFalse((data / name).exists(), name)
