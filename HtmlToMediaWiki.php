<?php

# Simple converter from HTML to MediaWiki
# Handles paragraphs, preformatted text, lists, bold/italic
# Copyright (c) 2010 Vitaliy Filippov

class HtmlToMediaWiki
{
    static $tags = array(
        'br' => array('empty' => 1, 'attr' => array('style' => 1, 'clear' => 1)),
        'pre' => array('prefix' => "\1\n", 'notrim' => 1, 'pre' => 1),
        'ul' => array('replacebegin' => "\1\n"),
        'ol' => array('replacebegin' => "\1\n"),
        'li' => array('replacebegin' => ""),
        'p' => array('replacebegin' => "\1\n\n", 'attr' => array('style' => 1, 'clear' => 1)),
        'a' => array('replacebegin' => '[$href ', 'replaceend' => ']', 'attr' => array('title' => 1)),
        'b' => array('replacebegin' => "'''", 'replaceend' => "'''"),
        'i' => array('replacebegin' => "''", 'replaceend' => "''"),
        'img' => array('html' => 1),
        'object' => array('prefix' => "\1\n\n", 'html' => 1),
        'iframe' => array('prefix' => "\1\n\n", 'html' => 1),
        'table' => array('html' => 1),
        'div' => array('attr' => array('style' => 1, 'clear' => 1)),
        'blockquote' => array('prefix' => "\1\n", 'attr' => array('style' => 1, 'clear' => 1)),
    );

    static function loadDOM($html)
    {
        $dom = new DOMDocument();
        $oe = error_reporting();
        error_reporting($oe & ~E_WARNING);
        $dom->loadHTML("<?xml version='1.0' encoding='UTF-8'?>".mb_convert_encoding($html, 'HTML-ENTITIES', 'UTF-8'));
        error_reporting($oe);
        return $dom;
    }

    static function domnodeopen($e)
    {
        $s = '<'.$e->nodeName;
        if ($e->hasAttributes())
            foreach ($e->attributes as $a)
                if (self::$tags[$e->nodeName]['attr'][$a->name])
                    $s .= ' '.htmlspecialchars($a->name).'="'.htmlspecialchars($a->value).'"';
        return $s;
    }

    static function domattr_replace($m)
    {
        global $domattr_re;
        return $m[1] . $domattr_re->attributes->getNamedItem($m[2])->value;
    }

    static function domattr($e, $s)
    {
        global $domattr_re;
        if (is_string($s))
        {
            $domattr_re = $e;
            $s = preg_replace_callback('/([^\\\\]|^)\\$([a-z0-9]+)/is', 'HtmlToMediaWiki::domattr_replace', $s);
            return $s;
        }
    }

    static function domhtml($element)
    {
        $xml = $element->ownerDocument->saveXML($element, LIBXML_NOEMPTYTAG);
        $xml = preg_replace('#(<(br|input|img)(\s+[^<>]*[^/])?)></\2>#', '\1 />', $xml);
        return $xml;
    }

    static function dom2wiki($e)
    {
        if ($e->nodeType == XML_TEXT_NODE)
        {
            $s = preg_replace('/\s+/', ' ', $e->nodeValue);
            if ($s == ' ')
                return '';
            return htmlspecialchars($s);
        }
        $t = self::$tags[$e->nodeName];
        if ($t['html'])
            return ($t['prefix'] !== NULL ? $t['prefix'] : '').'<html>'.self::domhtml($e).'</html>';
        if (!$e->childNodes->length)
        {
            if ($s = $t['replaceempty'])
                return self::domattr($e, $s);
            elseif ($t['empty'])
                return self::domnodeopen($e) . ' />';
            else
                return '';
        }
        $s = '';
        if ($e->nodeName == 'ol' || $e->nodeName == 'ul')
        {
            $old_r = self::$tags['li']['replacebegin'];
            self::$tags['li']['replacebegin'] = "\n".trim($old_r).($e->nodeName == 'ol' ? '#' : '*').' ';
        }
        if (!$t['pre'])
            foreach ($e->childNodes as $n)
                $s .= self::dom2wiki($n);
        else
            $s = $e->nodeValue;
        if ($old_r !== NULL)
            self::$tags['li']['replacebegin'] = $old_r;
        if (strpos($s, '<html>') !== false && $e->nodeName == 'a')
            return ($t['prefix'] !== NULL ? $t['prefix'] : '').'<html>'.mb_convert_encoding(self::domhtml($e), 'UTF-8', 'HTML-ENTITIES').'</html>';
        if (!$t['notrim'])
            $s = trim($s);
        if ($s == '' && !$t['empty'])
            return '';
        if (!$t) {}
        elseif (!array_key_exists('replacebegin', $t))
        {
            $s = self::domnodeopen($e).'>'.$s.'</'.$e->nodeName.'>';
            if (array_key_exists('prefix', $t))
                $s = $t['prefix'] . $s;
        }
        else
            $s = self::domattr($e, $t['replacebegin']).$s.self::domattr($e, $t['replaceend']);
        return $s;
    }

    static function html2wiki($html)
    {
        $html = preg_replace_callback('#(<pre[^<>]*>)(.*?)(</pre>)#is', create_function('$m', 'return $m[1].str_replace(array("<", ">"), array("&lt;", "&gt;"), $m[2]).$m[3];'), $html);
        $dom = self::loadDOM($html);
        $wiki = self::dom2wiki($dom->documentElement);
        $wiki = preg_replace('#\s*\x01#', '', $wiki);
        $wiki = trim($wiki);
        return $wiki;
    }
}