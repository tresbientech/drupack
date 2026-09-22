package runtime

import "regexp"

// MintedSegmentPattern is the rule every path segment Drupack mints from a
// version or a checksum must satisfy. It starts with a lowercase letter,
// because a PHP library that builds a path through preg_replace reads a
// backslash followed by a digit as a backreference and drops both, and it
// holds only lowercase letters, digits, '.' and '-' within a length that
// leaves a Windows path component's limit clear.
const MintedSegmentPattern = `^[a-z][a-z0-9.-]{0,31}$`

var mintedSegmentRe = regexp.MustCompile(MintedSegmentPattern)

// MintedSegment reports whether segment satisfies MintedSegmentPattern, so it
// is safe to mint as a cache directory name.
func MintedSegment(segment string) bool {
	return mintedSegmentRe.MatchString(segment)
}
