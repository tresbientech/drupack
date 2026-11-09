package main

import (
	"cmp"
	"errors"
	"fmt"
	"strconv"

	"git.tresbien.tech/tresbientech/drupack/launcher/internal/siteconfig"
	"git.tresbien.tech/tresbientech/drupack/launcher/internal/state"
)

// Site is what differs between the modes that ask for a served address.
type Site struct {
	// DefaultListen is the --listen value when neither the reader nor a record names one.
	DefaultListen string
	// Static is true for a project that sets a static listener.
	Static bool
	// Noun names the project in the static listener refusal.
	Noun string
	// AnyHost reads the listen address with the engine's parser, which takes a hostname.
	AnyHost bool
	// AdvisesRestart adds the way out of an unreadable record to its refusal.
	AdvisesRestart bool
}

// siteTarget is the Site of a Drupack site with Site data.
func siteTarget(site siteconfig.Site) Site {
	return Site{
		DefaultListen:  loopbackIP + ":" + strconv.Itoa(site.Port),
		Static:         site.Static != nil,
		Noun:           "site",
		AdvisesRestart: true,
	}
}

// Typed is what the reader typed. "" leaves a field to the record.
type Typed struct {
	Listen, StaticListen, Host, FilesDir string
}

// Address is where the last start served: the planned listeners, and what the
// record held that the plan does not.
type Address struct {
	startPlan
	// Recorded is false when no start has recorded a listener.
	Recorded bool
	// StaticRecorded is true when the record names a static listen address.
	StaticRecorded bool
	// FilesDir is "" when neither the reader nor the record names one.
	FilesDir string
}

// Mercure is the hub's URL.
func (a Address) Mercure() string {
	return mercureURL(a.Scheme, a.Bind, a.Port)
}

// Served reads the Listener record in dir and returns the address it served,
// with each typed value taking precedence over the recorded one and the
// recorded one over the default.
func Served(dir state.Dir, name string, s Site, t Typed) (Address, error) {
	record, recorded, err := dir.Listener()
	var unreadable *state.UnreadableError
	if errors.As(err, &unreadable) && s.AdvisesRestart {
		return Address{}, fmt.Errorf("%w. Remove that file, then start %s again to record it.", err, name)
	}
	if err != nil {
		return Address{}, err
	}
	scheme := cmp.Or(record.Scheme, state.UnrecordedScheme)
	listen := cmp.Or(t.Listen, record.Listen, s.DefaultListen)
	parse := func() (string, int, error) { return parseListen(listen, "--listen") }
	if s.AnyHost {
		parse = func() (string, int, error) { return engineListen(listen) }
	}
	bind, port, err := parse()
	if err != nil {
		return Address{}, err
	}
	// A reader types --host. A recorded one is the launcher's own.
	if t.Host != "" && !validHost(t.Host) {
		return Address{}, errors.New("Invalid --host value")
	}
	staticListen := cmp.Or(t.StaticListen, record.StaticListen)
	plan, err := planStart(bind, port, staticListen, s.Static, s.Noun, cmp.Or(t.Host, record.Host, unrecordedHost), scheme)
	if err != nil {
		return Address{}, err
	}
	return Address{plan, recorded, record.StaticListen != "", cmp.Or(t.FilesDir, record.FilesDir)}, nil
}
