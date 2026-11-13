//go:build !windows

package main

// setCRTEnv has nothing to do outside Windows: os.Setenv already sets the table
// PHP's getenv reads.
func setCRTEnv(string, string) error {
	return nil
}
