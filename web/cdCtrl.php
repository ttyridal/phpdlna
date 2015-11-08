<?php
/* php-dlna v1.0
 * Copyright 2014 Torbjørn Tyridal (phpdlna@tyridal.no)
 *
 * This file is part of php-dlna.

 *   php-dlna is free software: you can redistribute it and/or modify
 *   it under the terms of the GNU Affero General Public License version 3
 *   as published by the Free Software Foundation
 *
 *   php-dlna is distributed in the hope that it will be useful,
 *   but WITHOUT ANY WARRANTY; without even the implied warranty of
 *   MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 *   GNU Affero General Public License for more details.
 *
 *   You can get a copy The GNU Affero General Public license from
 *   http://www.gnu.org/licenses/agpl-3.0.html
 *
 * This program was initially a fork of umspx (https://code.google.com/p/umspx/)
 * umspx is copyright Tao Yu <yut616@gmail.com>
*/
error_reporting(E_ALL | E_STRICT);
/*
http://www.upnp.org/specs/av/UPnP-av-ContentDirectory-v1-Service.pdf
https://github.com/ronenmiz/TVersity/wiki/Content-Directory-Metadata
 */

require_once("config.php");
require_once("didl.php");
require_once("polyfills.php");

function _debug($someText, $someVar = null) {
    $logFile = "/tmp/phpdlna-debug.txt";
    $fh = fopen($logFile, 'a') or die();
    fwrite($fh, date('Y.m.d H:i:s') . ' ' . $someText . "\n");
    if ($someVar) fwrite($fh, print_r($someVar, true) . "\n");
    fclose($fh);
}

function demangle_fname($fname)
{
    return str_replace(array('.','_'),' ', $fname);
}

function sorted_filelist($path, $withfiles)
{
    $folders=array();
    $files=array();
    foreach (new DirectoryIterator($path) as $fileInfo) {
        if ($fileInfo->isDot()) continue;
        if ($fileInfo->isDir()) {
            $folders[] = $fileInfo->getFilename();
        } else if ($withfiles) {
            $files[] = $fileInfo->getFilename();
        }
    }
    sort($folders);
    if ($withfiles) {
        sort($files);
        return array($folders, $files);
    } else
        return $folders;
}

class InvalidInputException extends Exception { }

class ContentDirectory {
    protected $SystemUpdateID = '1';

    function __construct() {
//         $this->SystemUpdateID = ''.time();
    }

    private function lookup_real_path($id) {
        // hide realpath by mapping folders to numbers: 1.1.2 is second folder in the first folder
        // under the first configured root..  prevent infoleak by listing non-shared folders.
        $path_key = explode('.', $id);
        $key = intval(array_shift($path_key));
        if ($key > count(Config::$folders)) throw new InvalidInputException();
        $path = Config::$folders[$key-1]['hostpath'];
        $webpath=Config::$folders[$key-1]['webpath'];

        foreach ($path_key as $key) {
            $folderid=0;
            $folders = sorted_filelist($path, false);
            if ($key > count($folders)) throw new InvalidInputException();
            $path.=$folders[$key-1].'/';
            $webpath.=$folders[$key-1].'/';
        }
        return array($path, $webpath);
    }

    function Search($req) {
        //TODO: implement :)
        //TODO: Consider $req->StartingIndex and $req->RequestedCount
        _debug("Called unimplemented meth Search");
        $items = new DIDL(DIDL::ROOT_ID);
        return array('Result'=>$items->getXML(), 'NumberReturned'=>$items->count, 'TotalMatches'=>$items->count, 'UpdateID'=>$this->SystemUpdateID);
    }
    function BrowseMetadata($req)
    {
        _debug("Metadata of ".$req->ObjectID);

        //TODO (maybe): Consider $req->Filter

        if ($req->ObjectID == '0') {
            $items = new DIDL(DIDL::ROOT_ID);

            $items->addFolder("root", '0')
                ->searchclass("object.item.audioItem")
                ->searchclass("object.item.videoItem")
                ->searchclass("object.item.imageItem")
                ;
        } else {
            try {
                list($path, $webpath) = $this->lookup_real_path($req->ObjectID);
            } catch (InvalidInputException $e) {
                return array('illegal');
            }
            _debug("metadata for ".$path." : ".$webpath);

            if (substr_count($req->ObjectID, '.') == 0)
                $items = new DIDL('0');
            else {
                $pid = implode('.', array_slice(explode('.', $req->ObjectID), 0, -1));
                $items = new DIDL($pid);
            }
            $fname = demangle_fname(pathinfo($path, PATHINFO_FILENAME));
            $items->addFolder($fname, $req->ObjectID);
        }
        $totalMatches=$items->count;
        $items = $items->slice($req->StartingIndex, $req->RequestedCount);
        return array('Result'=>$items->getXML(), 'NumberReturned'=>$items->count, 'TotalMatches'=>$totalMatches, 'UpdateID'=>$this->SystemUpdateID);
    }

    function BrowseDirectChildren($req)
    {
        _debug("Direct children of ".$req->ObjectID);

        //TODO (maybe): Consider $req->Filter
        $items = new DIDL($req->ObjectID);
        $folderid = 0;

        if ($req->ObjectID == '0') { //ROOT
            foreach (Config::$folders as $folder) {
                $items->addFolder(basename($folder['webpath']), sprintf("%d", ++$folderid))
                ->creator('Creator')
                ->genre('Genre')
                ->artist('Artist')
                ->author('Author')
                ->album('Album')
                ->date('2014-01-01')
                ->actor('Actor')
                ->director('Director')
//                 ->icon(MEDIABASE.'/that_folder/folder.jpg')();
                ;
            }
        } else {
            try {
                list($path, $webpath) = $this->lookup_real_path($req->ObjectID);
            } catch (InvalidInputException $e) {
                return array('illegal');
            }

            _debug("listing: ".$path);
            list($folders, $files) = sorted_filelist($path, true);

            foreach ($folders as $f) {
                $itm = $items->addFolder($f, sprintf('%s.%d', $req->ObjectID, ++$folderid));
                foreach (array('folder.png','folder.jpg','album.png','album.jpg') as $icon) {
                    if (file_exists($path.$f.'/'.$icon)) {
                        $itm->icon($webpath.$f."/".$icon);
                        break;
                    }
                }
            }

            $finfo = finfo_open(FILEINFO_MIME_TYPE);
            foreach ($files as $f)
            {
                $ct = finfo_file($finfo, $path.$f);
                $res_opts = array('filesize'=>filesize($path.$f));

                $fname = pathinfo($f, PATHINFO_FILENAME);
                if (substr($ct, 0, 6) === 'video/') {
                    $itm = $items->addVideo(demangle_fname($fname));
                    $res_opts['protocolInfo'] = 'http-get:*:'.$ct.':*';
                } else if (substr($ct, 0, 6) === 'audio/') {
                    $itm = $items->addSong(demangle_fname($fname));
                    $res_opts['protocolInfo'] = 'http-get:*:'.$ct.':*';
                } else
                    continue;
                $itm->resource($webpath.$f, $res_opts)
                    //->creator('Creator')
                    //->genre('Genre')
                    //->artist('Artist')
                    //->artist('PerformingArtist', 'Performer')
                    //->artist('ComposerArtist', 'Composer')
                    //->artist('AlbumArtist', 'AlbumArtist')
                    //->author('Author')
                    //->album('Album')
                    //->date('2014-01-01')
                    //->actor('Actor')
                    //->director('Director')
                    //->album('AlbumName')
                    //->track('1')
                    //->longDescription("the long description")
                    //->description("short description")
                    //->language("English")
                    ;
                if (file_exists($path.$fname.'.png')) {
                    $itm->resource($webpath.$fname.'.png', array('protocolInfo'=>'http-get:*:image/png:DLNA.ORG_PN=PNG_TN'));
                    $itm->icon($webpath.$fname.'.png');
                }
                else if (file_exists($path.$fname.'.jpg')) {
                    $itm->resource($webpath.$fname.'.jpg', array('protocolInfo'=>'http-get:*:image/jpeg:DLNA.ORG_PN=JPEG_TN'));
                    $itm->icon($webpath.$fname.'.jpg');
                }
                else {
                    foreach (array('folder.png','folder.jpg','album.png','album.jpg') as $icon) {
                        if (file_exists($path.$icon)) {
                            $itm->icon($webpath.$icon);
                            break;
                        }
                    }
                }
            }
            finfo_close($finfo);
        }
        $totalMatches=$items->count;
        $items = $items->slice($req->StartingIndex, $req->RequestedCount);
        return array('Result'=>$items->getXML(), 'NumberReturned'=>$items->count, 'TotalMatches'=>$totalMatches, 'UpdateID'=>$this->SystemUpdateID);
    }

    function Browse($req) {
        if ($req->BrowseFlag == 'BrowseMetadata')
            return $this->BrowseMetadata($req);
        else
            return $this->BrowseDirectChildren($req);
    }

    function GetSystemUpdateID() {
        return array('Id'=>$this->SystemUpdateID);
    }

    function GetSearchCapabilities() {
//         return array('SearchCaps'=>'dc:creator,dc:title,upnp:album,upnp:actor,upnp:artist,upnp:class,upnp:genre,@refID');
        return array('SearchCaps'=>'');
    }
    function GetSortCapabilities() {
//         return array('SortCaps'=>'dc:title,dc:date,upnp:class,upnp:originalTrackNumber');
        return array('SortCaps'=>'');
    }

    /* From ConnectionManager.. but simple enough to handle it here */
    function GetProtocolInfo() {
        return array('Source' => file_get_contents('protocol_info.txt'), 'Sink'=>'');
    }
}

function move_namespace_to_first_user($soapXml, $ns='ns1')
{
    $marker1 = "xmlns:$ns=";
    $marker2 = "<$ns:";
    $startpos = strpos($soapXml, $marker1);
    $endpos = strpos($soapXml, "\"", $startpos + strlen($marker1) + 1);
    if ($startpos === FALSE) return $soapXml;

    $namespace = substr( $soapXml, $startpos, $endpos - $startpos + 1);

    $soapXml = str_replace(' '.$namespace, '', $soapXml);

    $insertpos = strpos($soapXml, '>', strpos($soapXml, $marker2));

    $soapXml = substr_replace( $soapXml, ' '.$namespace, $insertpos, 0 );
    return $soapXml;
}

$headers=array_change_key_case(getallheaders()); //ofcourse ther's a php function for that...
$body = @file_get_contents('php://input');
if (0)
    _debug("Request:",array('url'=>"$_SERVER[REQUEST_METHOD] http://$_SERVER[HTTP_HOST]$_SERVER[REQUEST_URI]", 'headers'=>$headers, 'body'=>$body));
else
    _debug("Request: ".$headers['soapaction']);

$srv = new SoapServer("wsdl/upnp_av.wsdl");
$srv->setClass('ContentDirectory');

ob_start();
$srv->handle($body);
$soapXml = ob_get_contents();
ob_end_clean();

// if someone knows a better way to convince SOAPServer to include the encodingStyle
// attribute, I'll be glad to hear about it.
// Required by the platinum upnp library (plex, xbmc others...)
$soapXml = str_replace('<SOAP-ENV:Envelope', '<SOAP-ENV:Envelope SOAP-ENV:encodingStyle="http://schemas.xmlsoap.org/soap/encoding/"', $soapXml);
// if someone knows a better way to control namespace scope, I'll be glad to hear about it!
// A lot of renderers are particularly picky about the xml (ps3, many more)
$soapXml = move_namespace_to_first_user($soapXml);

$length = strlen($soapXml);
header("Content-Length: ".$length);
echo $soapXml;
?>
