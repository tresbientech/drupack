"""Agent Access serves an MCP endpoint behind OAuth, with a key pair each site generates."""

import json
from urllib.error import HTTPError
from urllib.request import Request, urlopen

import harness

MCP_INITIALIZE = json.dumps({
    "jsonrpc": "2.0", "id": 1, "method": "initialize",
    "params": {"protocolVersion": "2025-06-18", "capabilities": {},
               "clientInfo": {"name": "drupack-conformance", "version": "1"}},
}).encode()


class AgentAccess(harness.ConformanceCase):
    PLATFORMS = (harness.LINUX, harness.MACOS, harness.WINDOWS)

    def setUp(self):
        super().setUp()
        self.sites = []

    def tearDown(self):
        for site in self.sites:
            site.stop()

    def start(self, name):
        site = harness.Site(harness.BINARY, self.case_dir / name)
        self.sites.append(site)
        site.start(self.case_dir / name / "data")
        return site

    def public_key(self, site):
        status, _, body = site.fetch("/oauth/jwks")
        self.assertEqual(200, status)
        return json.loads(body)["keys"][0]["n"]

    def test_the_metadata_names_the_served_host(self):
        site = self.start("site")
        status, _, body = site.fetch("/.well-known/oauth-authorization-server")
        self.assertEqual(200, status)
        registration = json.loads(body)["registration_endpoint"]
        self.assertEqual(f"http://localhost:{site.port}/oauth/register", registration)

    def test_mcp_refuses_a_request_without_a_token(self):
        site = self.start("site")
        request = Request(f"http://localhost:{site.port}/mcp", data=MCP_INITIALIZE, method="POST", headers={
            "Content-Type": "application/json", "Accept": "application/json, text/event-stream"})
        with self.assertRaises(HTTPError) as refused:
            urlopen(request, timeout=harness.WAITS["http_request"].seconds)
        self.assertEqual(401, refused.exception.code)
        self.assertIn("WWW-Authenticate", refused.exception.headers)

    def test_each_site_keeps_a_key_of_its_own(self):
        first = self.start("first")
        second = self.start("second")
        key = self.public_key(first)
        self.assertNotEqual(key, self.public_key(second))
        first.stop()
        first.start(self.case_dir / "first" / "data")
        self.assertEqual(key, self.public_key(first))
