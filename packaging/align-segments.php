<?php

declare(strict_types=1);

// UPX miscomputes a checksum for a segment that declares a large alignment and
// holds far less than half of it, which corrupts the packed file:
// https://github.com/upx/upx/issues/836, open since 2005 and present in UPX
// 5.2.1. PHP's .remap_stub segment is a few hundred bytes aligned to 2 MiB. Its
// address stays aligned, so recording a page-sized alignment changes nothing for
// the loader.
//
// UPX's own test decides whether a build ships, so this script reports what it
// changed and never fails a build. To find out whether UPX still needs it, build
// with DRUPACK_KEEP_ALIGNMENT=1: the executable then packs as PHP linked it.

const PAGE_ALIGNMENT = 0x1000;
const PROGRAM_HEADER_OFFSET = 0x20;
const PROGRAM_HEADER_SIZE = 0x36;
const PROGRAM_HEADER_COUNT = 0x38;
const SEGMENT_TYPE_LOAD = 1;
const ALIGNMENT_FIELD = 48;

$path = $argv[1];
$file = fopen($path, 'r+b');
if ($file === false) {
    throw new RuntimeException("Cannot open executable: $path");
}
$header = fread($file, 64);
$offset = unpack('P', substr($header, PROGRAM_HEADER_OFFSET, 8))[1];
$size = unpack('v', substr($header, PROGRAM_HEADER_SIZE, 2))[1];
$count = unpack('v', substr($header, PROGRAM_HEADER_COUNT, 2))[1];

$patched = 0;
for ($index = 0; $index < $count; $index++) {
    $position = $offset + $index * $size;
    fseek($file, $position);
    $entry = fread($file, $size);
    $type = unpack('V', substr($entry, 0, 4))[1];
    $fileSize = unpack('P', substr($entry, 32, 8))[1];
    $alignment = unpack('P', substr($entry, ALIGNMENT_FIELD, 8))[1];
    if ($type !== SEGMENT_TYPE_LOAD || $alignment <= PAGE_ALIGNMENT || $fileSize >= intdiv($alignment, 2)) {
        continue;
    }
    fseek($file, $position + ALIGNMENT_FIELD);
    fwrite($file, pack('P', PAGE_ALIGNMENT));
    printf("Segment %d holds %d bytes: alignment 0x%x becomes 0x%x\n", $index, $fileSize, $alignment, PAGE_ALIGNMENT);
    $patched++;
}
fclose($file);

if ($patched === 0) {
    // arm64 links the same PHP without that alignment, so it packs as it is.
    print "No segment needs realignment\n";
}
