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

function get_media_title($path) {
    return str_replace(array('.','_'),' ', pathinfo($path, PATHINFO_FILENAME));
}

function get_media_icon($path) {
    $dir = pathinfo($path, PATHINFO_DIRNAME).'/';
    $fname = pathinfo($path, PATHINFO_FILENAME);
    if (file_exists($dir.$fname.'.png')) return $fname.'.png';
    if (file_exists($dir.$fname.'.jpg')) return $fname.'.jpg';
    foreach (array('folder.png','folder.jpg','album.png','album.jpg') as $icon)
        if (file_exists($dir.$fname.'/'.$icon)) return $fname.'/'.$icon;
    return null;
}

function get_dlna_profile($path) {
    $finfo = finfo_open(FILEINFO_MIME_TYPE);
    $ct = finfo_file($finfo, $path);
    switch ($ct) {
        case 'image/png': $profile = 'DLNA.ORG_PN=PNG_TN'; break;
        case 'image/jpeg': $profile = 'DLNA.ORG_PN=JPEG_TN'; break;
        default: $profile = '*';
    }
    finfo_close($finfo);
    return $profile;
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
        list($id, $fileid) = array_pad(explode('$',$id, 2), 2, null);
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

        if ($fileid !== null) {
            list($folders, $files) = sorted_filelist($path, true);
            $fileid = intval($fileid);
            if ($fileid < 1 || $fileid > count($files)) throw new InvalidInputException();
            $path .= $files[$fileid - 1];
            $webpath .= $files[$fileid -1];
        }

        return array($path, $webpath);
    }

    function Search($req) {
        //TODO: implement :)
        //TODO: Consider $req->StartingIndex and $req->RequestedCount
        _debug("Called unimplemented meth Search");
        $items = new DIDL(DIDL::ROOT_PARENT_ID);
        return array('Result'=>$items->getXML(), 'NumberReturned'=>$items->count, 'TotalMatches'=>$items->count, 'UpdateID'=>$this->SystemUpdateID);
    }

    protected function BrowseMetadata($req)
    {
        _debug("Metadata of ".$req->ObjectID);

        //TODO (maybe): Consider $req->Filter

        if ($req->ObjectID == DIDL::ROOT_ID) {
            $items = new DIDL(DIDL::ROOT_PARENT_ID);

            $items->addFolder('root', $req->ObjectID)
                ->searchclass(DIDL::ITEM_CLASS_AUDIO)
                ->searchclass(DIDL::ITEM_CLASS_VIDEO);
        } else {
            try {
                list($path, $webpath) = $this->lookup_real_path($req->ObjectID);
            } catch (InvalidInputException $e) {
                return array('illegal');
            }
            _debug("metadata for ".$path." : ".$webpath);

            if (substr_count($req->ObjectID, '.') == 0)
                $pid = DIDL::ROOT_ID;
            else {
                if (is_dir($path))
                    $pid = implode('.', array_slice(explode('.', $req->ObjectID), 0, -1));
                else
                    $pid = explode('$', $req->ObjectID)[0];
            }
            $items = new DIDL($pid);

            if (is_dir($path))
                $items->addFolder(get_media_title($path), $req->ObjectID);
            else {
                $finfo = finfo_open(FILEINFO_MIME_TYPE);
                $ct = finfo_file($finfo, $path);
                $cls = DIDL::class_from_mime($ct);
                if (!$cls)
                    return array('illegal');

                $itm = $items->addItem($cls, get_media_title($path), $req->ObjectID);
                $itm->resource($webpath, array(
                    'filesize' =>filesize($path),
                    'protocolInfo' => 'http-get:*:'.$ct.':*'
                ));
                finfo_close($finfo);
            }
        }
        return array('Result'=>$items->getXML(), 'NumberReturned'=>1, 'TotalMatches'=>1, 'UpdateID'=>$this->SystemUpdateID);
    }

    protected function BrowseDirectChildren($req)
    {
        _debug("Direct children of ".$req->ObjectID);

        //TODO (maybe): Consider $req->Filter
        $items = new DIDL($req->ObjectID);
        $folderid = 0;
        $fileid = 0;

        if ($req->ObjectID == DIDL::ROOT_ID) {
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
                if (!is_dir($path)) throw new InvalidInputException();
            } catch (InvalidInputException $e) {
                return array('illegal');
            }

            _debug("listing: ".$path);
            list($folders, $files) = sorted_filelist($path, true);

            foreach ($folders as $f) {
                $itm = $items->addFolder(get_media_title($path.$f), sprintf('%s.%d', $req->ObjectID, ++$folderid));
                $icon = get_media_icon($path.$f);
                if($icon) {
//                     $itm->resource($webpath.$icon, array('protocolInfo'=>'http-get:*:'.$ct.':'.get_dlna_profile($path.$icon)));
                    $itm->icon($webpath.$icon);
                }
            }

            $finfo = finfo_open(FILEINFO_MIME_TYPE);
            foreach ($files as $f)
            {
                ++$fileid;
                $ct = finfo_file($finfo, $path.$f);
                $cls = DIDL::class_from_mime($ct);
                if (!$cls) continue;

                $itm = $items->addItem($cls, get_media_title($path.$f), sprintf('%s$%d', $req->ObjectID, $fileid));
                $itm->resource($webpath.$f, array(
                    'filesize' =>filesize($path.$f),
                    'protocolInfo' => 'http-get:*:'.$ct.':*'
                ));

                $icon = get_media_icon($path.$f);
                if($icon) {
                    $itm->resource($webpath.$icon, array('protocolInfo'=>'http-get:*:'.$ct.':'.get_dlna_profile($path.$icon)));
                    $itm->icon($webpath.$icon);
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
