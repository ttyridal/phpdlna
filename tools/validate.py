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
from lxml.builder import ElementMaker
try:
    from urllib.parse import urlparse
    from urllib.parse import urljoin
    from urllib.request import urlopen, Request
except ImportError:
    from urlparse import urlparse
    from urlparse import urljoin
    from urllib2 import urlopen, Request

MCAST_GRP   = "239.255.255.250"
MCAST_PORT  = 1900

def ansicolor_yellow(s): return "\033[93m%s\033[0m"%s
def ansicolor_green(s): return "\033[92m%s\033[0m"%s
def ansicolor_red(s): return "\033[91m%s\033[0m"%s


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
        if not 'upnp:rootdevice' in services:
            print(s, "@", "*NO upnp:rootdevice*","\n  answer from:", ":".join([str(x) for x in addr]))
        else:
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
    print("SCPD downloadable, and valid XML")
    return 0


def _soap_request(req, url, action):
    SOAPENV_NS = u'http://schemas.xmlsoap.org/soap/envelope/'
    S = ElementMaker(namespace=SOAPENV_NS, nsmap={'s':SOAPENV_NS})
    sr = S.Envelope( S.Body(req) )

    r = Request(url, headers={'soapaction' : action,
                              'content-type': 'text/xml; charset="utf-8"'})
    sr.attrib['{{{pre}}}encodingStyle'.format(pre=SOAPENV_NS)] = 'http://schemas.xmlsoap.org/soap/encoding/'
    l = urlopen(r,etree.tostring(sr,xml_declaration=True, encoding='utf-8')).read()
    return etree.fromstring(l)


def check_connection_manager(url):
    UPNP_CM_NS = u'urn:schemas-upnp-org:service:ConnectionManager:1'
    CM = ElementMaker(namespace=UPNP_CM_NS, nsmap={None:UPNP_CM_NS})

    l = _soap_request(
            CM.GetProtocolInfo(
            ),
            url, UPNP_CM_NS+u'#GetProtocolInfo')

    if not l.xpath('//cm:GetProtocolInfoResponse/Source/text()', namespaces={u'cm':UPNP_CM_NS}):
        print(ansicolor_red("Error GetProtocolInfo failed (no source)"))
        return -1
##     if not l.xpath('//cm:GetProtocolInfoResponse/Sink/text()', namespaces={u'cm':UPNP_CM_NS}):
##         print(ansicolor_red("Error GetProtocolInfo failed (no sink)"))
##         return -1
    print("ConnectionManager: GetProtocolInfo OK")


def _soap_upnp_browse(url, objectid):
    UPNP_CD_NS = u'urn:schemas-upnp-org:service:ContentDirectory:1'
    CD = ElementMaker(namespace=UPNP_CD_NS, nsmap={None:UPNP_CD_NS})

    l = _soap_request(
            CD.Browse(
                CD.ObjectID(objectid),
                CD.BrowseFlag('BrowseDirectChildren'),
                CD.Filter('*'),
                CD.StartingIndex('0'),
                CD.RequestedCount('8'),
                CD.SortCriteria(),
            ),
            url, UPNP_CD_NS+u'#Browse')

    l = l.xpath('//cd:BrowseResponse/Result/text()', namespaces={u'cd':UPNP_CD_NS})[0]
    return etree.fromstring(l)


def _find_playable_item(ctrlurl, didl_root):
    NS = {u'didl':u'urn:schemas-upnp-org:metadata-1-0/DIDL-Lite/',
          u'dc':u'http://purl.org/dc/elements/1.1/' }

    didl = didl_root
    path=[]
    while not didl.xpath('//didl:item', namespaces=NS):
        c = didl.xpath('//didl:container[1]',namespaces=NS)
        if not c:
            print(ansicolor_yellow("Warning, unable to find a downloadable item (path:/%s)"%('/'.join(path))))
            print(ansicolor_yellow("browse_server test can not verify file access"))
            return (None, None)
        c=c[0]
        didl = _soap_upnp_browse(ctrlurl, c.attrib['id'])
        path.append(c.xpath('//dc:title/text()',namespaces=NS)[0])
    c = didl.xpath('//didl:item[1]', namespaces=NS)[0]
    path.append(c.xpath('//dc:title/text()',namespaces=NS)[0])
    url = c.xpath('//didl:res/text()',namespaces=NS)[0]
    return path, url


def test_http_HEAD(url):
    r = Request(url)
    r.get_method = lambda : 'HEAD'
    try: l = urlopen(r)
    except Exception as e:
        print(ansicolor_red("http HEAD request failed: %s"%str(e)))
        return -1

    try: l.getheader # python3
    except: l.getheader = l.info().getheader #python2

    if l.getheader('Accept-Ranges', None) is None:
        print(ansicolor_yellow("Warning: Server does not provide Accept-Ranges"))
    elif not 'bytes' in l.getheader('Accept-Ranges'):
        print(ansicolor_yellow("Warning: Server does not provide Accept-Ranges: bytes"))
    if l.getheader('Content-Length', None) is None:
        print(ansicolor_yellow("Warning: Server does not provide Content-Length"))
    if l.getheader('Content-Type', None) is None:
        print(ansicolor_yellow("Warning: Server does not provide Content-Type"))
    elif not (l.getheader('Content-Type').startswith('audio/') or
              l.getheader('Content-Type').startswith('video/')):
        print(ansicolor_yellow("Warning: Content type not audio nor video !?"))


def browse_server(ctrlurl):
    NS = {u'didl':u'urn:schemas-upnp-org:metadata-1-0/DIDL-Lite/',
          u'dc':u'http://purl.org/dc/elements/1.1/' }

    try: didl = _soap_upnp_browse(ctrlurl, '0')
    except Exception as e:
        print("upnp browse request failed: %s"%str(e))
        return -1

    print("server toplevel folders: ",didl.xpath('//dc:title/text()',namespaces=NS))

    path, url = _find_playable_item(ctrlurl, didl)
    if path is None: return -1

    # browse to an item we can test-download
    print('Trying to fetch /'+("/".join(path)),"\n  @ ",url)

    if test_http_HEAD(url):
        return -1

    print(ansicolor_green("Server browsable, and file downloadable"))


def check_server(descurl):
    ns={'upnp':'urn:schemas-upnp-org:device-1-0'}
    try: l = urlopen(descurl).read()
    except Exception as e:
        print(ansicolor_red("Failed to load %s: %s"%(descurl,e)))
        return -1
    try: l = etree.fromstring(l)
    except Exception as e:
        print(ansicolor_red("Failed to parse rootdesc XML: %s"%e))
        return -1

    x = l.xpath('//upnp:URLBase[1]/text()', namespaces=ns)
    if not x:
        print(ansicolor_yellow("No URLBase in in rootdesc"))
        baseurl=""
    else: baseurl = x[0]

    v = l.xpath('//upnp:serviceType[text() = \'urn:schemas-upnp-org:service:ConnectionManager:1\']', namespaces=ns)
    if len(v) != 1:
        print(ansicolor_red("there should be one ConnectionManager Service! (missing from rootdesc)"))
        return -1
    x = v[0].getparent().findtext('upnp:controlURL', namespaces=ns)
    if x is None:
        print(ansicolor_red("Missing controlURL on ConnectionManager"))
        return -1
    if check_connection_manager(urljoin(baseurl, x)):
        return -1

    v = l.xpath('//upnp:serviceType[text() = \'urn:schemas-upnp-org:service:ContentDirectory:1\']', namespaces=ns)
    if len(v) != 1:
        print(ansicolor_red("there should be one ContentDirectory Service! (missing from rootdesc)"))
        return -1
    x = v[0].getparent().findtext('upnp:SCPDURL', namespaces=ns)
    if x is None:
        print(ansicolor_red("Missing SCPDURL in ContentDirectory"))
        return -1
    if check_scpd(urljoin(baseurl, x)):
        return -1
    x = v[0].getparent().findtext('upnp:controlURL', namespaces=ns)
    if x is None:
        print(ansicolor_red("Missing controlURL in ContentDirectory"))
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
            print(ansicolor_red("Failed"))
            return -1
        else:
            print(ansicolor_green("Passed"))



if __name__ == '__main__':
    sys.exit(main())
