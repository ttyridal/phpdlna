#!/usr/bin/env python
## coding=utf-8

# validate.py
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
#
#
#  This tool can be used to validate the installation.
#  It will check:
#    * That the announce server is responding
#    * Urls are reachable
#    * Responses are sane
#    * Browse and fetch a file (TODO)


from __future__ import print_function
import socket
import select
import time
import sys
import re
import lxml.etree as etree
try:
    from urllib.parse import urlparse
    from urllib.parse import urljoin
except ImportError:
    from urlparse import urlparse
    from urlparse import urljoin
try:
    from urllib.request import urlopen
except ImportError:
    from urllib import urlopen

MCAST_GRP   = "239.255.255.250"
MCAST_PORT  = 1900

def ansicolor_yellow(s):
    return "\033[93m%s\033[0m"%s
def ansicolor_green(s):
    return "\033[92m%s\033[0m"%s
def ansicolor_red(s):
    return "\033[91m%s\033[0m"%s

def find_servers():
    s1 = b'\r\n'.join([
    b'M-SEARCH * HTTP/1.1',
    b'Man: "ssdp:discover"',
    b'Mx: 3',
    b'Host: 239.255.255.250:1900',
    b'St: ssdp:all',
    b'',b''])

    s2 = b'\r\n'.join([
    b'M-SEARCH * HTTP/1.1',
    b'Man: "ssdp:discover"',
    b'Mx: 5',
    b'Host: 239.255.255.250:1900',
    b'St: upnp:rootdevice',
    b'',b''])

    sock = socket.socket(socket.AF_INET, socket.SOCK_DGRAM)
    sock.sendto(s1, (MCAST_GRP,MCAST_PORT))

    serverlist={}
    try:
        now = start = time.time()
        timeout = 9
        while now - timeout < start:
            sys.stderr.write("\rWaiting % 2us for servers, %d found"%(start+timeout-now, len(serverlist.keys())))
            rlist, _, _ = select.select([sock], [], [], 1)
            now = time.time()
            if not rlist: continue
            msg, addr = sock.recvfrom(1500)
            msg = dict(re.findall(r'(?P<name>.*?): (?P<value>.*?)\r?\n', msg.decode('utf-8')))
            onaddr = serverlist.get(addr,{})
            onaddr['SERVER'] = msg['SERVER']
            onaddr[msg['ST']] = msg['LOCATION']
            serverlist[addr]=onaddr
    except KeyboardInterrupt: pass
    finally:
        sys.stderr.write('\r'+' '*36+'\n')
        sock.close()

    phpdlna_servers = []
    for addr,services in serverlist.items():
        if 'PHPDLNA' in services['SERVER']:
            s = ansicolor_green(services['SERVER'])
            phpdlna_servers.append((addr[0], services['upnp:rootdevice']))
        else:
            s = services['SERVER']
        print(s, "@", services['upnp:rootdevice'],"\n  answer from:", ":".join([str(x) for x in addr]))

        for st,loc in services.items():
            if st == 'SERVER': continue
            print(" ",st)
    return phpdlna_servers

def check_scpd(scdpurl):
    try:
        l = urlopen(scdpurl).read()
    except Exception as e:
        print(ansicolor_red("Failed to load %s: %s"%(scdpurl,e)))
        return -1
    try:
        l = etree.fromstring(l)
    except Exception as e:
        print(ansicolor_red("Failed to parse scdp XML: %s"%e))
        return -1

    print(ansicolor_yellow("Warning: scpd fetchable but not validated (not impl)"))
    return 0

def browse_server(ctrlurl):
    print(ansicolor_yellow("Warning: dlna-Browse request not tested (not impl)"))
    pass

def check_server(descurl):
    ns={'upnp':'urn:schemas-upnp-org:device-1-0'}
    try:
        l = urlopen(descurl).read()
    except Exception as e:
        print(ansicolor_red("Failed to load %s: %s"%(descurl,e)))
        return -1
    try:
        l = etree.fromstring(l)
    except Exception as e:
        print(ansicolor_red("Failed to parse rootdesc XML: %s"%e))
        return -1
    baseurl = [ etree.tostring(x, method='text').strip().decode('utf-8') for x in l.xpath('//upnp:URLBase', namespaces=ns) ][0]

    v = [ x for x in l.xpath('//upnp:serviceType', namespaces=ns) if etree.tostring(x, method='text').strip() == b"urn:schemas-upnp-org:service:ContentDirectory:1" ]

    if len(v)<1:
        print(ansicolor_red("no ContentDirectory Service!"))
        return -1
    if len(v)>1:
        print(ansicolor_red("more than one ContentDirectory Service!"))
        return -1
    x = v[0].getparent().findtext('upnp:SCPDURL', namespaces=ns)
    if x is None:
        print(ansicolor_red("Missing SCPDURL"))
        return -1
    if check_scpd(urljoin(baseurl, x)):
        return -1
    x = v[0].getparent().findtext('upnp:controlURL', namespaces=ns)
    if x is None:
        print(ansicolor_red("Missing controlURL"))
        return -1

    if browse_server(urljoin(baseurl, x)):
        return -1

    return 0


def main():
    servers = find_servers()
    if not servers:
        print(ansicolor_red("No PHPDLNA servers found"))
        return -1

    for s in servers:
        print("\nchecking server at",s[0])
        if urlparse(s[1]).netloc.split(':')[0] != s[0].split(':')[0]:
            print(ansicolor_yellow("Warning: announcer address not equal config.h:server_location"))
        if check_server(s[1]):
            return -1
        else:
            print(ansicolor_green("Passed"))



if __name__ == '__main__':
    sys.exit(main())
