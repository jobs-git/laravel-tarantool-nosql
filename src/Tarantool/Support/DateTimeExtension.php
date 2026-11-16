<?php

namespace Tarantool\Nosql\Support;

use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;
use MessagePack\BufferUnpacker;
use MessagePack\Extension;
use MessagePack\Packer;

final class DateTimeExtension implements Extension
{
    private const MP_DATETIME = 4;

    public function getType(): int
    {
        return self::MP_DATETIME;
    }

    public function pack(Packer $packer, mixed $value): ?string
    {
        if (!$value instanceof DateTimeInterface) {
            return null;
        }

        $seconds  = $value->getTimestamp();
        $nsec     = (int) $value->format('u') * 1000;
        $tzoffset = (int) ($value->getOffset() / 60); // minutes east of UTC

        if ($nsec === 0 && $tzoffset === 0) {
            return $packer->packExt(self::MP_DATETIME, pack('P', $seconds));
        }

        $data = pack('P', $seconds)
            . pack('V', $nsec)
            . pack('v', $tzoffset & 0xFFFF) // signed int16, little-endian
            . pack('v', 0); // tzindex

        return $packer->packExt(self::MP_DATETIME, $data);
    }

    public function unpackExt(BufferUnpacker $unpacker, int $extLength): mixed
    {
        $data = $unpacker->read($extLength);

        ['sec' => $seconds] = unpack('Psec', substr($data, 0, 8));

        $nsec = 0;
        $tzoffset = 0;

        if ($extLength >= 16) {
            ['nsec' => $nsec, 'tzoffset' => $rawOffset] = unpack(
                'Vnsec/vtzoffset',
                substr($data, 8, 6)
            );
            $tzoffset = $rawOffset >= 32768 ? $rawOffset - 65536 : $rawOffset;
        }

        $dt = DateTimeImmutable::createFromFormat(
            'U.u',
            sprintf('%d.%06d', $seconds, intdiv($nsec, 1000))
        );

        $tz = new DateTimeZone(sprintf(
            '%+03d:%02d',
            intdiv($tzoffset, 60),
            abs($tzoffset) % 60
        ));

        return $dt->setTimezone($tz);
    }
}