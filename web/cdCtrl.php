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

require_once("didl.php");

function _debug($someText, $someVar = null) {
    $logFile = "/tmp/phpdlna-debug.txt";
    $fh = fopen($logFile, 'a') or die();
    fwrite($fh, date('Y.m.d H:i:s') . ' ' . $someText . "\n");
    if ($someVar) fwrite($fh, print_r($someVar, true) . "\n");
    fclose($fh);
}

define('MEDIABASE','http://'.$_SERVER['HTTP_HOST'].'/media');

class ContentDirectory {
    function Search($req) {
        //TODO: implement :)
        //TODO: Consider $req->StartingIndex and $req->RequestedCount
        $items = new DIDL(DIDL::ROOT_ID);
        return array('Result'=>$items->getXML(), 'NumberReturned'=>$items->count, 'TotalMatches'=>$items->count, 'UpdateID'=>13);
    }
    function Browse($req) {
        //TODO: Consider $req->StartingIndex and $req->RequestedCount
        //TODO (maybe): Consider $req->Filter

        # The objectId should be '0' for the root container.
        if ($req->BrowseFlag == 'BrowseDirectChildren')
            $items = new DIDL($req->ObjectID);
        else if ($req->ObjectID == '0') {
            _debug("root metadata: ".$req->BrowseFlag);
            $items = new DIDL(DIDL::ROOT_ID);

            $items->addFolder("root", '0')
                ->searchclass("object.item.audioItem")
                ->searchclass("object.item.videoItem")
                ->searchclass("object.item.imageItem");
            return $items;
        } else {
            _debug("Don't really know what to do here objid=".$req->ObjectID);
            $items = new DIDL(0);
        }
        _debug("Direct children of ".$req->ObjectID);
        if ($req->ObjectID == '0') {
            $items->addFolder("that folder", $req->ObjectID.'1')
            ->icon(MEDIABASE.'/that_folder/folder.jpg')
            ->creator('Creator')
            ->genre('Genre')
            ->artist('Artist')
            ->author('Author')
            ->album('Album')
            ->date('2014-01-01')
            ->actor('Actor')
            ->director('Director')
            ;
        }
        $items->addSong('A song')
            ->resource(MEDIABASE.'/music/a_song.mp3' /*, 'http-get:*:audio/mpeg:DLNA.ORG_PN=MP3;DLNA.ORG_OP=01;DLNA.ORG_CI=0'*/)
            ->icon(MEDIABASE.'/music/a_song.jpg')
            ->creator('Creator')
            ->genre('Genre')
            ->artist('Artist')
            ->artist('PerformingArtist', 'Performer')
            ->artist('ComposerArtist', 'Composer')
            ->artist('AlbumArtist', 'AlbumArtist')
            ->author('Author')
            ->album('Album')
            ->date('2014-01-01')
            ->actor('Actor')
            ->director('Director')
            ->album('AlbumName')
            ->track('1')
            ;
        $items->addVideo('A Video')
            ->resource(MEDIABASE.'/video/a_video.mkv')
            ->icon(MEDIABASE.'/video/a_video.jpg')
            ->creator('Creator')
            ->genre('Genre')
            ->artist('Artist')
            ->author('Author')
            ->album('Album')
            ->date('2014-01-01')
            ->actor('Actor')
            ->director('Director')
            ->album('AlbumName')
            ->track('1')
            ->longDescription("the long description")
            ->description("short description")
            ->language("English")
            ;

        return array('Result'=>$items->getXML(), 'NumberReturned'=>$items->count, 'TotalMatches'=>$items->count, 'UpdateID'=>13);
    }

    function GetSystemUpdateID() {
        return array('Id'=>'13');
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


$srv = new SoapServer("wsdl/upnp_av.wsdl");
$srv->setClass('ContentDirectory');
$srv->handle();
?>
