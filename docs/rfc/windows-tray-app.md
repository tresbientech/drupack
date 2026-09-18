# Windows tray app

Proposed on 2026-09-17. Parked, in favour of a clearer command line first.

## Question

A double-click on `drupack-<version>-windows-amd64.exe` shows nothing useful. The launcher is a console program, so Explorer opens a console window. The first start then exits with "Missing Drupal administrator credentials", because Explorer passes no options, and the window closes before anyone can read the message. How should Windows users start a site without a terminal?

## Shape

A tray app runs a Packaged site in the background under the signed-in user and shows an icon in the notification area. It is not a Windows service: services run in session 0, cannot show a notification icon, need admin rights to install, and run under a machine account.

Two executables ship from one source, because a Windows executable is either a console program or a GUI program:

- `drupack-<version>-windows-amd64.exe` becomes the GUI tray app, for a double-click.
- `drupack-cli-<version>-windows-amd64.exe` stays the console program, for the terminal, `dr` and scripts.

Both unpack into the same runtime cache, so the second download costs about 113 MB and no extra disk.

## Site data and first start

The tray app serves the `data` directory next to its own executable, resolved from the executable path. A sign-in entry or a shortcut can start a program in `C:\Windows\System32`, where a working-directory path would create the site.

A first start creates the account `admin` with a random password, then opens the browser with a Drupal one-time login link. The user sets a password on the Drupal account page and never sees the generated one.

Consequences:

- An executable in a directory it cannot write, such as `Program Files`, fails with an error naming the directory.
- A WinGet portable install keeps the executable in `%LOCALAPPDATA%\Microsoft\WinGet\Packages\<id>`, so Site data lands there. `winget uninstall` keeps files WinGet did not create. `winget uninstall --purge` deletes the site.

## Behaviour

- The first start uses port 7225 when it is free, otherwise the next free port from 7226. The tray app records the port in Site data, so bookmarks survive. A later conflict selects a new port and shows it in a notification.
- A second double-click on Site data that is already served asks the running tray app to open the browser, then exits. `drupack-cli` refuses to serve Site data that a tray app serves. `dr` commands keep working.
- Nothing starts at sign-in. A double-click start opens the browser.
- The icon appears immediately in a starting state, with the menu disabled. A first start also shows the notification "Setting up your site. This takes up to a minute." A failed start shows the error in a notification.

## Menu

1. The site address, such as `http://127.0.0.1:7225`, disabled
2. Open site
3. Log in as administrator, which creates a one-time login link
4. Copy MCP client configuration, the output of `dr mcp-tools:client-config`
5. Open Site data folder
6. Open log, the server log file in Site data
7. Quit, which stops the site

Quit is the only way to stop a site.

## Icon

A Drupack icon carries three states: normal, starting and error. The Drupal trademark policy excludes the Druplicon, whose use needs separate licensing from the Drupal Association. It permits the official Drupal logo only in a standalone, unaltered form, which a 16-pixel icon with state badges is not.

## Tests

`DRUPACK_RUNTIME_TRAY=off` runs the tray app without an icon or notifications. A Pester test then covers the port file, a first start, the handover from a second start, the refusal in `drupack-cli`, the log file, and that Quit stops every process. A person checks the menu once per release.

## Out of scope

- A macOS menu bar app. It needs an `.app` bundle to avoid Terminal, and an unsigned bundle meets Gatekeeper quarantine, so it follows Apple signing and notarization.
- Linux. Notification area support differs between desktop environments, and GNOME has none without an extension.
