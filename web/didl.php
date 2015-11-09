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

// http://www.upnp.org/schemas/av/didl-lite-v2.xsd
function addNodeWithText($parent, $tag, $text) {
    $ndTag = $parent->ownerDocument->createElement($tag);
    $ndTag_text = $parent->ownerDocument->createTextNode((mb_detect_encoding($text,'auto')=='UTF-8')?$text:utf8_encode($text));
    $ndTag->appendChild($ndTag_text);
    $parent->appendChild($ndTag);
    return $ndTag;
}

class DIDLitem {
    protected $node=NULL;
    function __construct($node) { $this->node = $node; }
//         function album_art($url) { addNodeWithText($this->node, 'upnp:albumArtURI', $url)
// //             ->setAttribute('profileID','PNG_TN');
//             ->setAttribute('profileID','JPEG_TN');
//             return $this;
//         }
    function icon($url) { addNodeWithText($this->node, 'upnp:icon', $url); return $this; }
    function creator($value) { addNodeWithText($this->node, 'dc:creator', $value); return $this; }
    function genre($value) { addNodeWithText($this->node, 'upnp:genre', $value); return $this; }
    function artist($value, $role=NULL) {
        $n = addNodeWithText($this->node, 'upnp:artist', $value);
        if ($role!==NULL) $n->setAttribute('role', $role);
        return $this; }
    function author($value) { addNodeWithText($this->node, 'upnp:author', $value); return $this; }
    function album($value) { addNodeWithText($this->node, 'upnp:album', $value); return $this; }
    function track($value) { addNodeWithText($this->node, 'upnp:originalTrackNumber', $value); return $this; }
    function actor($value) { addNodeWithText($this->node, 'upnp:actor', $value); return $this; }
    function director($value) { addNodeWithText($this->node, 'upnp:director', $value); return $this; }
    function date($value) { addNodeWithText($this->node, 'dc:date', $value); return $this; }
    function searchclass($value) { addNodeWithText($this->node, 'upnp:searchClass', $value)->setAttribute('includeDerived', '1'); return $this; }
    function longDescription($value) { addNodeWithText($this->node, 'upnp:longDescription', $value); return $this; }
    function description($value) { addNodeWithText($this->node, 'dc:description', $value); return $this; }
    function language($value) { addNodeWithText($this->node, 'dc:language', $value); return $this; }
    function resource($url, $optattr=array())
    {
        $optattr = array_merge(array('protocolInfo'=>'*:*:*:*'), $optattr);
        $ndRes = $this->node->ownerDocument->createElement('res');
        $ndRes->setAttribute('protocolInfo', $optattr['protocolInfo']);
        if (array_key_exists('filesize', $optattr)) $ndRes->setAttribute('size', $optattr['filesize']);
        if (array_key_exists('duration', $optattr)) $ndRes->setAttribute('duration', $optattr['duration']);
        if (array_key_exists('bitrate', $optattr)) $ndRes->setAttribute('bitrate', $optattr['bitrate']);
        if (array_key_exists('resolution', $optattr)) $ndRes->setAttribute('resolution', $optattr['resolution']);
//         $ndRes->setAttribute('bitrate', ""+(3780)); //kbps
// //         $ndRes->setAttribute('sampleFrequency', "48000");
// //         $ndRes->setAttribute('nrAudioChannels', "6");
//         $ndRes->setAttribute('resolution', "1280x720");
        $ndRes_text = $this->node->ownerDocument->createTextNode($url);
        $ndRes->appendChild($ndRes_text);
        $this->node->appendChild($ndRes);
        return $this;
    }
}
class DIDL {
    protected $didldoc=NULL;
    protected $didlroot=NULL;
    public $parent_id=NULL;
    public $count=0;
    const ROOT_ID = '0';
    const ROOT_PARENT_ID = '-1';
    const ITEM_CLASS_VIDEO = 'object.item.videoItem';
    const ITEM_CLASS_AUDIO = 'object.item.audioItem';
    const ITEM_CLASS_IMAGE = 'object.item.imageItem';
    function __construct($parent_id) {
        $this->didldoc = new DOMDocument('1.0', 'utf-8');
        $this->didldoc->formatOutput = true;

        $this->didlroot = $this->didldoc->createElementNS('urn:schemas-upnp-org:metadata-1-0/DIDL-Lite/', 'DIDL-Lite');
        $this->didlroot->setAttribute('xmlns:dc', 'http://purl.org/dc/elements/1.1/');
        $this->didlroot->setAttribute('xmlns:upnp', 'urn:schemas-upnp-org:metadata-1-0/upnp/');
        $this->didlroot->setAttribute('xmlns:dlna', 'urn:schemas-dlna-org:metadata-1-0/');
        $this->didldoc->appendChild($this->didlroot);

        $this->parent_id = $parent_id;
    }

    public static function class_from_mime($ct) {
        if (substr($ct, 0, 6) === 'video/') return DIDL::ITEM_CLASS_VIDEO;
        if (substr($ct, 0, 6) === 'audio/') return DIDL::ITEM_CLASS_AUDIO;
        return null;
    }

    function addFolder($title, $id) {
        $ndItem = $this->didldoc->createElement('container');
        $ndItem->setAttribute('id', $id);
        $ndItem->setAttribute('parentID', $this->parent_id);
        $ndItem->setAttribute('restricted', '1');
//         $ndItem->setAttribute('childCount', '1');
        addNodeWithText($ndItem, 'dc:title', $title);
        addNodeWithText($ndItem, 'upnp:class', 'object.container.storageFolder');
        $this->didlroot->appendChild($ndItem);
        $this->count++;
        return new DIDLitem($ndItem);
    }
    function addItem($class, $title, $id) {
        $ndItem = $this->didldoc->createElement('item');
        $ndItem->setAttribute('id', $id);
        $ndItem->setAttribute('parentID', $this->parent_id);
        $ndItem->setAttribute('restricted', '1');
        addNodeWithText($ndItem, 'dc:title', $title);
        $this->didlroot->appendChild($ndItem);
        $this->count++;
        addNodeWithText($ndItem, 'upnp:class', $class);
        return new DIDLitem($ndItem);
    }
    function slice($startidx, $cnt=-1) {
        // todo: clone items to $that instead of destroying $this
        // $that = new DIDL($this->parent_id);
        $i = $this->didlroot->firstChild;
        while ($startidx>0 && $this->count>0) {
            $startidx--;
            $c = $i;
            $i = $i->nextSibling;
            $this->didlroot->removeChild($c);
            $this->count--;
        }
        if ($cnt<0 || $this->count<$cnt) return $this;
        $this->count=$cnt;
        while($cnt>0 && $i!==NULL) {
            $cnt--;
            $i=$i->nextSibling;
        }
        while($i!==NULL) {
            $c=$i;
            $i=$i->nextSibling;
            $this->didlroot->removeChild($c);
        }
        return $this;
    }
    function getXML($no_header=true) {
        return $this->didldoc->saveXML($no_header?$this->didldoc->firstChild:NULL); // get first child to avoid xml header.
    }
}

?>
