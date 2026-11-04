<?php

function check_port_effective_ports($cachedListeningPort, $iVersion, $readPort)
{
    $listeningPort = (int) $cachedListeningPort;
    // Settings are cached, but Force Port changes the live socket immediately.
    if ($iVersion >= 0x809) {
        $live = filter_var(
            $readPort('network.listen.port'),
            FILTER_VALIDATE_INT,
            ['options' => ['min_range' => 1, 'max_range' => 65535]],
        );
        if ($live !== false) {
            $listeningPort = $live;
        }
    }

    $ports = ['listen' => $listeningPort, 'ipv4' => $listeningPort, 'ipv6' => $listeningPort];
    // 0.16.21 added per-family advertised ports; zero keeps the listening port.
    if ($iVersion >= 0x1015) {
        foreach (['ipv4', 'ipv6'] as $family) {
            $localPort = filter_var(
                $readPort("network.local_port.$family"),
                FILTER_VALIDATE_INT,
                ['options' => ['min_range' => 1, 'max_range' => 65535]],
            );
            if ($localPort !== false) {
                $ports[$family] = $localPort;
            }
        }
    }
    return $ports;
}
