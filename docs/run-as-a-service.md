# Run the engine as a service

`drupack` serves a Composer project on a server, under systemd or OpenRC. The
engine leaves three jobs to the server:

- It runs no Drupal cron.
- It serves plain HTTP on any address other than loopback.
- It reads no proxy settings for Drupal. The project's `settings.php` holds
  them.

The examples serve a project in `/srv/site` as the account `drupack`.

## The account and the project

Create an account with a home directory, and give it the project:

```sh
useradd --system --create-home --home-dir /var/lib/drupack drupack
chown -R drupack: /srv/site
```

On Alpine, `adduser -S -D -h /var/lib/drupack drupack` creates it.

`drupack` unpacks its runtime into the account's cache, `~/.cache/drupack`. An
account without a home sets `XDG_CACHE_HOME` and `XDG_DATA_HOME` to a folder it
alone can read and write, for the service and for every `drupack` command.

[Install `drupack`](../README.md#serve-a-drupal-project-you-already-have) in
the project as that account. With the two Composer scripts, `composer install
--no-dev` leaves the release the lock names in `/srv/site/drupack`.

## systemd

`/etc/systemd/system/drupack.service`:

```ini
[Unit]
Description=Drupack serving /srv/site
After=network-online.target
Wants=network-online.target

[Service]
User=drupack
WorkingDirectory=/srv/site
ExecStart=/srv/site/drupack --foreground --listen 127.0.0.1:3787 --trusted-proxies 127.0.0.1
Restart=on-failure
TimeoutStopSec=15

[Install]
WantedBy=multi-user.target
```

- `--foreground` keeps the server as the unit's main process. Its lines reach
  the journal.
- A stop sends SIGTERM. The server exits 0 within 10 seconds, so
  `TimeoutStopSec=15` never kills it.
- `systemctl enable --now drupack` starts it, now and at each boot.

Drupal's cron runs from a timer. `/etc/systemd/system/drupack-cron.service`:

```ini
[Unit]
Description=Drupal cron of /srv/site

[Service]
Type=oneshot
User=drupack
WorkingDirectory=/srv/site
ExecStart=/srv/site/drupack drush cron
```

`/etc/systemd/system/drupack-cron.timer`:

```ini
[Unit]
Description=Drupal cron of /srv/site, every three hours

[Timer]
OnCalendar=0/3:00:00
Persistent=true

[Install]
WantedBy=timers.target
```

`systemctl enable --now drupack-cron.timer` starts the timer. Turn off Drupal's
Automated Cron module, which runs cron inside a visitor's request.

## Alpine and OpenRC

Alpine takes the musl build, which the install script picks. `/etc/init.d/drupack`,
mode 755:

```sh
#!/sbin/openrc-run

description="Drupack serving /srv/site"
command=/srv/site/drupack
command_args="--foreground --listen 127.0.0.1:3787 --trusted-proxies 127.0.0.1 /srv/site"
command_user="drupack:drupack"
command_background=yes
pidfile=/run/drupack.pid
output_log=/var/log/drupack.log
error_log=/var/log/drupack.log
# A stop takes 10 seconds at most.
retry="SIGTERM/15"

depend() {
	need net
}

start_pre() {
	checkpath -f -o drupack:drupack -m 0640 /var/log/drupack.log
}
```

`rc-update add drupack default` starts it at boot, and `rc-service drupack
start` starts it now.

Cron runs from busybox `crond`. `/etc/crontabs/drupack`, owned by root, mode
600:

```
0 */3 * * * cd /srv/site && ./drupack drush cron 2>&1 | logger -t drupack-cron
```

## A project two accounts share

On many servers one account deploys the project and another serves it. Drupal
writes configuration exports and uploaded files into the project as the service
account. Under the usual umask, `022`, the deploy account cannot rewrite them,
and the next deploy fails on a file it does not own.

Put both accounts in one group, make the project's files group-writable, and
start the server with umask `002`. The engine keeps the umask it starts with.

- systemd: `UMask=0002` in the `[Service]` section.
- OpenRC: `start_stop_daemon_args="--umask 002"` in the service file.

## HTTPS through a proxy

A proxy in front holds the certificate and forwards plain HTTP to `drupack`.

### A proxy on the same machine

`drupack` listens on `127.0.0.1:3787`, as in the examples above. A loopback
listener answers `localhost`, its own address and the Site host, and gives any
other name an empty response. The proxy therefore sends the listener's address
as `Host`, and the public name as `X-Forwarded-Host`. Caddy does it with one
`header_up` line:

```
www.example.org {
	reverse_proxy 127.0.0.1:3787 {
		header_up Host {upstream_hostport}
	}
}
```

### A proxy on another machine

`drupack` listens on the server's own address and trusts the proxy's:

```
/srv/site/drupack --foreground --listen 192.0.2.10:3787 --trusted-proxies 192.0.2.5
```

Such a listener answers every name, so the proxy forwards `Host` unchanged.
The port serves plain HTTP: let the firewall admit the proxy alone.

### The project's settings

`--trusted-proxies` puts the visitor's address in the access log. Drupal reads
the forwarded address, scheme and host once `settings.php` names the proxy:

```php
$settings['reverse_proxy'] = TRUE;
$settings['reverse_proxy_addresses'] = ['127.0.0.1'];
$settings['trusted_host_patterns'] = ['^www\.example\.org$'];
```

`reverse_proxy_addresses` holds the address the proxy connects from.

## Logs

`--access-log=FILE` and `--error-log=FILE` write Caddy's request lines and
PHP's errors to files, which roll at 100 MiB. The service account must be able
to create them. [Logs](cli.md#logs) describes both options.

## Deploying a new version

`drupack` has no reload. After `composer install` changed the project or the
engine, restart the service: `systemctl restart drupack` or `rc-service drupack
restart`. Requests that arrive during the restart fail.
