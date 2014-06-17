#!/usr/bin/python
## coding=utf-8

# php-dlna v1.0 - UPnP SSDP
# Copyright 2014 Torbjørn Tyridal (phpdlna@tyridal.no)
#
# This file is part of php-dlna.
#
#   php-dlna is free software: you can redistribute it and/or modify
#   it under the terms of the GNU Affero General Public License version 3
#   as published by the Free Software Foundation
#
#   php-dlna is distributed in the hope that it will be useful,
#   but WITHOUT ANY WARRANTY; without even the implied warranty of
#   MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
#   GNU Affero General Public License for more details.
#
#   You can get a copy The GNU Affero General Public license from
#   http://www.gnu.org/licenses/agpl-3.0.html

from socket import socket, inet_aton, IPPROTO_IP, IP_ADD_MEMBERSHIP, IP_DROP_MEMBERSHIP, \
                   AF_INET, SOCK_DGRAM, SOL_SOCKET, SO_REUSEADDR, INADDR_ANY, IP_MULTICAST_TTL
import struct

def read_c_constant_string(fromfile, string):
    with open(fromfile,"r") as f:
        for l in f:
            if l.startswith("const char %s[]"%string):
                return l.split("=",1)[1].strip().strip(";").strip('"')

upnp_broadcast_addr   = "239.255.255.250"
upnp_broadcast_port  = 1900
BUFFER_SIZE = 1500

server_location = read_c_constant_string("config.h", 'server_location')
device_uuid = read_c_constant_string("config.h", 'device_uuid')
server_id = read_c_constant_string("config.h", 'server_id')

# client will typically disconnect (even just stop playing
# the current media) if we do not reannouce within this time.
cache_age = 60*60*12 #s; == 12 hours



common_headers = [
    'CACHE-CONTROL: max-age=%d'%cache_age,
    'LOCATION: %s'%server_location,
    'SERVER: %s'%server_id,
##     'X-User-Agent: redsonic',
    ]
services = [
    'upnp:rootdevice',
    'urn:schemas-upnp-org:device:MediaServer:1',
    'urn:schemas-upnp-org:service:ConnectionManager:1',
    'urn:schemas-upnp-org:service:ContentDirectory:1',
    ##'urn:microsoft.com:service:X_MS_MediaReceiverRegistrar:1'
    ]

unicast_socket = socket(AF_INET, SOCK_DGRAM)

def notify(sock, status="alive"):
    assert(status in ['alive','byebye'])
    hdrs=['NOTIFY * HTTP/1.1',
          'HOST: %s:%d'%(upnp_broadcast_addr,upnp_broadcast_port),
        ]

    m = "\r\n".join(hdrs+
            common_headers+
            ['NT: uuid:%s'%device_uuid, 'USN: uuid:%s'%device_uuid, 'NTS: ssdp:%s'%status,'',''])
    sock.sendto(m, (upnp_broadcast_addr, upnp_broadcast_port))
    for s in services:
        m = "\r\n".join(hdrs+
                common_headers+
                ['NT: %s'%s, 'USN: uuid:%s::%s'%(device_uuid, s), 'NTS: ssdp:%s'%status,'',''])
        sock.sendto(m, (upnp_broadcast_addr, upnp_broadcast_port))

def msearch_reply(addr):
    for s in services:
        m = "\r\n".join(['HTTP/1.1 200 OK','EXT:']+
                common_headers+
                ['ST: %s'%s, 'USN: uuid:%s::%s'%(device_uuid, s),'',''])
        unicast_socket.sendto(m, addr)

unicast_socket.setsockopt(IPPROTO_IP, IP_MULTICAST_TTL, 3)
notify(unicast_socket)

#broadcast listen:

broadcast_socket = socket(AF_INET, SOCK_DGRAM)
broadcast_socket.setsockopt(SOL_SOCKET, SO_REUSEADDR, 1)
mreq = struct.pack('=4sl', inet_aton(upnp_broadcast_addr), INADDR_ANY) # pack upnp_broadcast_addr correctly
broadcast_socket.setsockopt(IPPROTO_IP, IP_ADD_MEMBERSHIP, mreq)       # Request upnp_broadcast_addr
broadcast_socket.bind((upnp_broadcast_addr, upnp_broadcast_port))      # Bind to all intfs

try:
    while True:
        msg, addr = broadcast_socket.recvfrom(BUFFER_SIZE)
        if msg.startswith("M-SEARCH"):
            print "got m-search"

            print str(addr)+":\n  "  + "\n  ".join(msg.replace("\r","\\r").split("\n"))
            msearch_reply(addr)
except KeyboardInterrupt: pass
finally:
    broadcast_socket.setsockopt(IPPROTO_IP, IP_DROP_MEMBERSHIP, mreq)
    broadcast_socket.close()
    unicast_socket.close()

