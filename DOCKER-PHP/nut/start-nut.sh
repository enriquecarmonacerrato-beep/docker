#!/bin/bash
set -e

# Iniciar UPS dummy en modo standalone
echo "Iniciando NUT..."
/usr/sbin/upsd -D
/usr/sbin/upssched -D &
/usr/sbin/upsmon -D -c /etc/nut/upsmon.conf
