phpdlna
=======

UPnP / DLNA server in PHP - use an existing web server as media hub


Many UPnP / DLNA Media Servers (or Digital Media Server (DMS) in DLNA speach)
have been written. As far as I have found, they all include web server (and 
soap+xml framework)

If you already have a web server installed, and considering that UPnP is 
basically http with a tiny module doing UDP broadcasts for discovery,
one might wonder why an additional http server is necessary.

With php-dlna, the answer is: It's not.

php-dlna provides a small udp client that takes care of the discovery (sending
upnp notify and answering m-search requests). Everything is then directed to
the php script on your existing web server.


##Configuration

tools/config.h:
server location should point to the rootDesc.xml (see below)

web/rootDesc.xml: 
 URLBase should be updated to your configuration
 UDN should match config.h
 URL's assume that the /web folder is accessible at
 "URLBASE/phpdlna"

web/config.php:
 Define the folders to share. See config.php\_example

##Misc

Why?
* Scratch that itch
* Reduce the amount of server software to keep up to date.

Features:
Basic DLNA-DMS / UPnP MediaServer file system serving
TODO: search/sort would be nice

Transcoding:
Transcoding is currently not implemented. It should be fairly easy to
have php-dlna call eg ffmpeg and do transcoding on the fly. It would be
nice if it could do ConnectionManager request to the player for zero-user-
interaction format negociation.


annonce.cpp vs announce.py - UDP broadcast client:
They do exactly the same thing. One can be compiled and run without
dependencies.

##Technical notes
* UDP discovery cache-control:
Some renderers will simply stop playing and disconnect if a notify is not 
received when the time expires. 

* Same source ip
Some renderers (notably wdtv live) will not accept that the web server
and udp-broadcast lives on different addresses (location has to point
to the same ip as the notify / m-search reply is sent from)

* UPnP Events
are not implemented. I've not found a client that requires them. Files
exists to avoid http-errors.
