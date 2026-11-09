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
	// AnyHost reads the listen address with the engine's parser, which takes a hostname.
	AnyHost bool
	// AdvisesRestart adds the way out of an unreadable record to its refusal.
	AdvisesRestart bool
}

// siteTarget is the Site of a Drupack site with Site data.
func siteTarget(site siteconfig.Site) Site {
	return Site{DefaultListen: loopbackIP + ":" + strconv.Itoa(site.Port), AdvisesRestart: true}
}

// target is the Site of the engine's project.
func (f engineProject) target() Site {
	return Site{DefaultListen: defaultListen, AnyHost: true}
}

// Typed is what the reader typed. "" leaves a field to the record.
type Typed struct {
	Listen, StaticListen, Host, FilesDir string
}

// Address is where the last start served.
type Address struct {
	Bind         string
	Port         int
	Host, Scheme string
	// StaticListen is the typed static listen address, else the recorded one, unparsed.
	StaticListen string
	// Recorded is false when no start has recorded a listener.
	Recorded bool
	// StaticRecorded is true when the record names a static listen address.
	StaticRecorded bool
	// FilesDir is "" when neither the reader nor the record names one.
	FilesDir string
}

func (a Address) URL() string {
	return siteURL(a.Scheme, a.Host, a.Port)
}

// Mercure is the hub's URL.
func (a Address) Mercure() string {
	return mercureURL(a.Scheme, a.Bind, a.Port)
}

// Plan plans the static listener beside the main address, which refuses a
// hostname listen: the static address is built from the bind address.
func (a Address) Plan(hasStatic bool, noun string) (startPlan, error) {
	return planStart(a.Bind, a.Port, a.StaticListen, hasStatic, noun, a.Host, a.Scheme)
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
	listen := cmp.Or(t.Listen, record.Listen, s.DefaultListen)
	var bind string
	var port int
	if s.AnyHost {
		bind, port, err = engineListen(listen)
	} else {
		bind, port, err = parseListen(listen, "--listen")
	}
	if err != nil {
		return Address{}, err
	}
	// A reader types --host. A recorded one is the launcher's own.
	if t.Host != "" && !validHost(t.Host) {
		return Address{}, errors.New("Invalid --host value")
	}
	return Address{
		Bind:           bind,
		Port:           port,
		Host:           cmp.Or(t.Host, record.Host, unrecordedHost),
		Scheme:         cmp.Or(record.Scheme, state.UnrecordedScheme),
		StaticListen:   cmp.Or(t.StaticListen, record.StaticListen),
		Recorded:       recorded,
		StaticRecorded: record.StaticListen != "",
		FilesDir:       cmp.Or(t.FilesDir, record.FilesDir),
	}, nil
}
