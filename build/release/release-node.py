#!/usr/bin/env python3
"""The drupal.org release node of a version tag. Packagist takes each
drupal/drupack version from one, and drupal.org has no API that creates it.

    python3 build/release/release-node.py check            stops while Packagist lists no release for the highest tag
    python3 build/release/release-node.py notes TAG PHAR   prints the release notes of TAG, as HTML
    python3 build/release/release-node.py entry TAG        prints what the release form takes for TAG, as Markdown
    python3 build/release/release-node.py session          stops when the session no longer opens the release form
    python3 build/release/release-node.py form TAG         prints the fields of the form's second step for TAG
    python3 build/release/release-node.py post TAG         creates the release of TAG

entry and post read the notes from standard input. PHAR is drupalorg-cli, which writes the notes
from the commits since the tag before.

session, form and post act as nod_bot. DRUPAL_ORG_SESSION holds the Cookie request header of a
browser logged in on www.drupal.org, copied whole from the developer tools' Network tab. The form
has two steps: the first picks the tag and saves nothing, the second saves. form stops between them.
"""

import hashlib
import json
import os
import re
import subprocess
import sys
import urllib.error
import urllib.parse
import urllib.request
from html.parser import HTMLParser

from upstream import PACKAGIST_METADATA, fetch, release_key

PACKAGE = "drupal/drupack"
HOST = "www.drupal.org"
SITE = f"https://{HOST}/"
# 3624016 is the node of the drupal.org project.
FORM = f"{SITE}node/add/project-release/3624016"
PROJECT = f"{SITE}project/drupack"
RELEASES = f"{PROJECT}/releases/"
# The headings of drupalorg-cli's notes, with the release type each one stands for on the form
# and that type's term on drupal.org.
RELEASE_TYPES = {"Bug": ("Bug fixes", 99), "Feature": ("New features", 98)}
# The line drupalorg-cli leaves for a person to replace.
PLACEHOLDER = "<p><em>Add a summary here</em></p>\n"
TAG_FIELD = "field_release_vcs_label[und][0][value]"
NOTES_FIELD = "body[und][0][value]"
# drupal.org staff place the requests in their logs by this name.
AGENT = f"drupack-release (+{PROJECT})"
# Drupal ties both to one session, so they are not printed.
HIDDEN = ("form_build_id", "form_token")
# Drupal names a host's session cookie after the host, so new.drupal.org's cookie has another name.
SESSION_NAME = "SSESS" + hashlib.sha256(HOST.encode()).hexdigest()[:32]


class SameSite(urllib.request.HTTPRedirectHandler):
    """Follows a redirect inside the site, such as the no_cache one a session gets. A redirect
    to another host, such as the login's, would carry the session cookie there, so it raises."""

    def redirect_request(self, request, response, code, message, headers, url):
        if not url.startswith(SITE):
            return None
        return super().redirect_request(request, response, code, message, headers, url)


def as_nod_bot(url, sent=None):
    """The body of URL, asked with the session cookie, and with SENT posted as a form when given.
    An expired session answers with a redirect to the login's host, which stops the run."""
    if not url.startswith(SITE):
        sys.exit(f"{url} is outside {SITE}, and the session cookie goes nowhere else.")
    session = os.environ["DRUPAL_ORG_SESSION"]
    # A person pastes this value, so its form is checked. The value itself is never printed.
    if not re.fullmatch(rf"[ -~]*\b{SESSION_NAME}=[\w-]+(;[ -~]*)?", session):
        sys.exit(f"DRUPAL_ORG_SESSION holds {len(session)} characters and no {HOST} session cookie, "
                 f"{SESSION_NAME}=<value>.")
    request = urllib.request.Request(url, data=sent and urllib.parse.urlencode(sent).encode(),
                                     headers={"Cookie": session, "User-Agent": AGENT})
    try:
        with urllib.request.build_opener(SameSite).open(request, timeout=60) as response:
            return response.read().decode()
    except urllib.error.HTTPError as error:
        sys.exit(f"{error.url} answered {error.code}, towards {error.headers.get('Location')}.")


class Forms(HTMLParser):
    """Every form of a page as {"id", "action", "controls"}, each control with its attributes and its options."""

    def __init__(self, page):
        super().__init__()
        self.forms = []
        self.textarea = False
        self.feed(page)

    def handle_starttag(self, tag, attributes):
        attributes = dict(attributes)
        if tag == "form":
            self.forms.append({"id": attributes.get("id"), "action": attributes.get("action"), "controls": []})
        elif tag in ("input", "select", "textarea", "button") and self.forms:
            self.forms[-1]["controls"].append({"tag": tag, "options": [], **attributes})
            self.textarea = tag == "textarea"
        elif tag == "option" and self.forms:
            self.forms[-1]["controls"][-1]["options"].append(attributes)

    def handle_data(self, data):
        if self.textarea:
            control = self.forms[-1]["controls"][-1]
            control["value"] = control.get("value", "") + data

    def handle_endtag(self, tag):
        self.textarea = False


def accepted(page):
    """PAGE, unless it refuses a form. Drupal answers a refused form with a page that states why."""
    refusal = re.search(r'<div class="messages error">(.*?)</div>', page, re.DOTALL)
    if refusal:
        reason = re.sub(r"(<[^>]+>|\s)+", " ", refusal.group(1)).strip(" .")
        sys.exit(f"drupal.org refused the form: {reason}.")
    return page


def release_form(page):
    for form in Forms(accepted(page)).forms:
        if form["id"] == "project-release-node-form":
            return form
    sys.exit("drupal.org gave a page with no release form.")


def filled(form):
    """What a browser sends for FORM before a button is pressed."""
    sent = {}
    for control in form["controls"]:
        kind = control.get("type", control["tag"])
        if control["tag"] == "select":
            sent.update((control["name"], option.get("value", "")) for option in control["options"] if "selected" in option)
        elif kind in ("checkbox", "radio"):
            if "checked" in control:
                sent[control["name"]] = control.get("value", "on")
        elif kind not in ("submit", "image", "button", "file"):
            sent[control["name"]] = control.get("value", "")
    return sent


def second_step(tag):
    """The form's second step for TAG, or None when the first step does not offer TAG.
    The first step offers the tags that have no release."""
    first = release_form(as_nod_bot(FORM))
    offered = [option.get("value") for control in first["controls"] if control.get("name") == TAG_FIELD
               for option in control["options"]]
    if tag not in offered:
        return None
    return release_form(as_nod_bot(FORM, {**filled(first), TAG_FIELD: tag, "op": "Next"}))


def describe(form):
    lines = [f"form {form['id']} -> {form['action']}"]
    for control in form["controls"]:
        value = "(hidden)" if control.get("name") in HIDDEN else control.get("value")
        lines.append(f"  {control['tag']} {control.get('type', '')} name={control.get('name')} value={value!r}"
                     f"{' checked' if 'checked' in control else ''}")
        lines.extend(f"    option {option.get('value')!r}{' selected' if 'selected' in option else ''}"
                     for option in control["options"])
    return "\n".join(lines)


def unlisted(tags, listed):
    highest = max(tags, key=release_key)
    return None if highest in listed else highest


def previous(tags, tag):
    return max((other for other in tags if release_key(other) < release_key(tag)), key=release_key)


def release_types(notes):
    return [release_type for heading, release_type in RELEASE_TYPES.items() if f"<h4>{heading}</h4>" in notes]


def form_entry(tag, notes):
    return "\n".join([
        f"### drupal.org release for {tag}",
        "",
        "The `node` job posts this release. When it fails, the same by hand:",
        "",
        f"1. Open the [release form]({FORM}) and pick the tag `{tag}`.",
        f"2. Release type: {', '.join(name for name, term in release_types(notes)) or 'none'}.",
        "3. Paste the notes.",
        "",
        "```html",
        notes.strip(),
        "```",
    ])


def git_tags():
    return subprocess.run(["git", "tag", "--list"], check=True, capture_output=True, text=True).stdout.split()


def check():
    entries = json.loads(fetch(f"{PACKAGIST_METADATA}{PACKAGE}.json"))["packages"][PACKAGE]
    tag = unlisted(git_tags(), [entry["version"] for entry in entries])
    if tag is not None:
        sys.exit(f"Packagist lists no {PACKAGE} {tag}. Create its release at {FORM}. "
                 f"The notes job of the Upstream run that tagged {tag} holds the text to paste.")


def notes(tag, phar):
    print(subprocess.run(["php", phar, "mrn", previous(git_tags(), tag), tag, "--format=html"],
                         check=True, capture_output=True, text=True).stdout, end="")


def entry(tag):
    print(form_entry(tag, sys.stdin.read()))


def session():
    release_form(as_nod_bot(FORM))


def form(tag):
    second = second_step(tag)
    print(describe(second) if second else f"The form offers no {tag}.")


def post(tag):
    """Creates the release of TAG and reads it back. A tag the form no longer offers has its
    release, so nothing is posted for it and a second run of one release changes nothing."""
    text = sys.stdin.read().replace(PLACEHOLDER, "")
    second = second_step(tag)
    if second:
        ticked = {f"taxonomy_vocabulary_7[und][{term}]": term for name, term in release_types(text)}
        accepted(as_nod_bot(FORM, {**filled(second), **ticked, NOTES_FIELD: text, "op": "Save"}))
    as_nod_bot(f"{RELEASES}{tag}")
    print(f"{RELEASES}{tag}")


COMMANDS = {"check": check, "notes": notes, "entry": entry, "session": session, "form": form, "post": post}

if __name__ == "__main__":
    COMMANDS[sys.argv[1]](*sys.argv[2:])
