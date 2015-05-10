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


Be aware that this is not a polished product that will automatically work out of the box. 
You'll probably need to compile some c-code (or run some python scripts) and change 
various files.  You'll also need to install and configure the web server Apache 
(others might work, not tested) - That's kind of the point. If you don't already have
a webserver, you'll be better of with [PS3 media server](http://www.ps3mediaserver.org/) [ReadyMedia](https://wiki.archlinux.org/index.php/ReadyMedia) or similar 

## Installing
Copy the files in phpdlna.git/web/* to a convenient location on your web server:

in phpdlna.git:

```mkdir /var/www/phpdlna && cp -a web/* /var/www/phpdlna/```

In phpdlna.git/tools:

```cp config.h.src config.h```

and edit config.h as appropriate. Usually the server_location string should be enough.
It is suggested to use the **IP** address (host name might work, depending on your
rendering device).

If you're going to use the C version of announce, you now need to compile it:
```g++ announce.cpp -o announce```.
The python version does not need compiling - and uses the same config.h for it's settings.

Further we'll need *rootdesc.xml*.

in /var/www/phpdlna/:

```cp rootDesc.xml.src rootDesc.xml```

and update ```URLBase``` to point to the root folder on your
server. If you chose another location than *phpdlna* for installation,
you'll also need to update the various *URL entries.


##Configuring media sources

The files to serve must be configured in /var/www/phpdlna/config.php.
An example with is provided in config.php_example.

Additionally it is required that the web server will serve your
files, as specified.

Further the web server needs to set the correct headers. You may use ```.htaccess```
files, or configure this in the main Apache config. see htaccess_example for details
- and your favourite search engine, on how to configure Apache.

After testing that you can actually access your media files with a browser, curl or
similar, say a few prayers and test from your renderer (WDTV, xbmc or others?)..

Oh, and before that will work - You'll need to start the announcer, and let it run:

```phpdlna.git/tools/announce &```


You can use the tools/validate.py to verify and debug the installation

```python phpdlna.git/tools/validate.py```

### Ngnix support
phpdlna has been tested to work with nginx and php-fpm

example nginx.conf:
```
# this assumes phpdlna.git/web is installed in /var/www/phpdlna
server {
#other stuff like server_name and listen
root /var/www;

#types are typically includes in a global
#mime_types, but listed here are typical types
#needed for dlna
types {
    image/png           png;
    image/jpeg          jpeg jpg;
    audio/mpeg          mp3;
    video/mpeg          mpeg mpg;
    video/x-matroska    mkv;
    video/mp4           mp4;
    video/quicktime     mov;
    video/x-flv         flv;
    video/x-msvideo     avi;
    video/x-ms-wmv      wmv;
    text/xml    xml;
}

location / {
    try_files $uri =404;
}

location ~ /phpdlna/.*\.php$ {
    #the param is also globally defined somewhere (usually)
    fastcgi_param   SCRIPT_FILENAME         $document_root$fastcgi_script_name;
    fastcgi_pass unix:/var/run/php5-fpm.sock;
}
}
```


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

* Server strings
Are specified in http://upnp.org/specs/arch/UPnP-arch-DeviceArchitecture-v1.1.pdf to
"MUST include 'UPnP/M.m'. On most servers we can't control this. wdtv and
xbmc at least doesn't care
