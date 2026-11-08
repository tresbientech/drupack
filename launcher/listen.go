package main

import (
	"fmt"
	"net"
	"regexp"
	"strconv"
	"strings"
)

var (
	// listenRe splits HOST:PORT at the colon before the port.
	listenRe = regexp.MustCompile(`^(.+):([0-9]+)$`)
	// ipShapeRe is the host a site's listener takes: IPv4, or IPv6 in brackets.
	ipShapeRe = regexp.MustCompile(`^(\[[0-9a-fA-F:]+\]|[0-9.]+)$`)
	// hostRe is one or more DNS labels, as PHP's FILTER_FLAG_HOSTNAME reads them.
	hostRe = regexp.MustCompile(`^[A-Za-z0-9]([A-Za-z0-9-]{0,61}[A-Za-z0-9])?(\.[A-Za-z0-9]([A-Za-z0-9-]{0,61}[A-Za-z0-9])?)*\.?$`)
)

// splitListen splits HOST:PORT. ok is false for a value with no port.
func splitListen(listen string) (host, port string, ok bool) {
	parts := listenRe.FindStringSubmatch(listen)
	if parts == nil {
		return "", "", false
	}
	return parts[1], parts[2], true
}

// parseListen splits an IP:PORT the reader wrote. option names the option in
// the refusal.
func parseListen(listen, option string) (bind string, port int, err error) {
	host, digits, ok := splitListen(listen)
	if !ok || !ipShapeRe.MatchString(host) {
		return "", 0, fmt.Errorf("%s requires IP:PORT, with IPv6 enclosed in brackets", option)
	}
	bind = strings.Trim(host, "[]")
	port, convertErr := strconv.Atoi(digits)
	if net.ParseIP(bind) == nil || convertErr != nil || port < 1 || port > 65535 {
		return "", 0, fmt.Errorf("Invalid %s address or port", option)
	}
	return bind, port, nil
}

// engineListen splits the engine's --listen, which takes any host before a
// port of up to five digits, and the Listener record the engine wrote from it.
func engineListen(listen string) (bind string, port int, err error) {
	host, digits, ok := splitListen(listen)
	port, _ = strconv.Atoi(digits)
	if !ok || len(digits) > 5 || port > 65535 {
		return "", 0, fmt.Errorf("--listen takes IP:PORT, got %s", listen)
	}
	return strings.Trim(host, "[]"), port, nil
}

// staticListener resolves --static-listen: option, or the site listener's
// address on the port after port.
func staticListener(option, bind string, port int) (staticBind string, staticPort int, err error) {
	if option == "" {
		option = urlHost(bind) + ":" + strconv.Itoa(port+1)
	}
	staticBind, staticPort, err = parseListen(option, "--static-listen")
	if err == nil && staticPort == port {
		err = fmt.Errorf("--static-listen must name a port other than the site's %d", port)
	}
	return staticBind, staticPort, err
}

// validHost reports whether host, a reader's --host, is a hostname or an IP address.
func validHost(host string) bool {
	return len(host) <= 253 && hostRe.MatchString(host) || net.ParseIP(host) != nil
}

// urlHost brackets an IPv6 address.
func urlHost(host string) string {
	if strings.Contains(host, ":") {
		return "[" + host + "]"
	}
	return host
}

func siteURL(scheme, host string, port int) string {
	return scheme + "://" + urlHost(host) + ":" + strconv.Itoa(port) + "/"
}

// probeHost is the address a wildcard bind answers on: its own family's loopback.
func probeHost(bind string) string {
	switch bind {
	case "0.0.0.0":
		return loopbackIP
	case "::":
		return "::1"
	}
	return bind
}

// mercureURL is the hub's address, and the iss and aud of every token.
func mercureURL(scheme, bind string, port int) string {
	return siteURL(scheme, probeHost(bind), port) + ".well-known/mercure"
}
