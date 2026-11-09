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
	DefaultListen string
	// Engine takes a hostname listen and gives an unreadable record no restart advice.
	Engine bool
}

func siteTarget(site siteconfig.Site) Site {
	return Site{DefaultListen: loopbackIP + ":" + strconv.Itoa(site.Port)}
}

func (f engineProject) target() Site {
	return Site{DefaultListen: defaultListen, Engine: true}
}

// Address is where the last start served. The record holds each typed value,
// else the recorded one, else the default. Recorded is false without a record.
type Address struct {
	state.Listener
	Bind     string
	Port     int
	Recorded bool
}

func (a Address) URL() string {
	return siteURL(a.Scheme, a.Host, a.Port)
}

func (a Address) Mercure() string {
	return mercureURL(a.Scheme, a.Bind, a.Port)
}

// Plan refuses a hostname listen: it builds the static address from the bind address.
func (a Address) Plan(hasStatic bool, noun string) (startPlan, error) {
	return planStart(a.Bind, a.Port, a.StaticListen, hasStatic, noun, a.Host, a.Scheme)
}

// Served returns the address the Listener record in dir served, with typed values first.
func Served(dir state.Dir, name string, s Site, typed state.Listener) (Address, error) {
	record, recorded, err := dir.Listener()
	var unreadable *state.UnreadableError
	if errors.As(err, &unreadable) && !s.Engine {
		return Address{}, fmt.Errorf("%w. Remove that file, then start %s again to record it.", err, name)
	}
	if err != nil {
		return Address{}, err
	}
	record.Listen = cmp.Or(typed.Listen, record.Listen, s.DefaultListen)
	record.StaticListen = cmp.Or(typed.StaticListen, record.StaticListen)
	record.FilesDir = cmp.Or(typed.FilesDir, record.FilesDir)
	record.Scheme = cmp.Or(record.Scheme, state.UnrecordedScheme)
	// A reader types --host. A recorded one is the launcher's own.
	if typed.Host != "" && !validHost(typed.Host) {
		return Address{}, errors.New("Invalid --host value")
	}
	record.Host = cmp.Or(typed.Host, record.Host, unrecordedHost)
	var bind string
	var port int
	if s.Engine {
		bind, port, err = engineListen(record.Listen)
	} else {
		bind, port, err = parseListen(record.Listen, "--listen")
	}
	if err != nil {
		return Address{}, err
	}
	return Address{record, bind, port, recorded}, nil
}
