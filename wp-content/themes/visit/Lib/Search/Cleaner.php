<?php


namespace VisitMarche\ThemeWp\Lib\Search;

class Cleaner
{
    public static function cleandata($data)
    {
        $data = wp_strip_all_tags($data);
        $data = preg_replace("#&nbsp;#", " ", $data);
        $data = preg_replace("#&amp;#", " ", $data);//&
        $data = preg_replace("#&#", " ", $data);
        $data = preg_replace("#<#", "", $data);
        $data = preg_replace("#’#", "'", $data);
        $data = preg_replace(array("#\(#", "#\)#"), "", $data);
        $special_chars = array(
            "?",
            "[",
            "]",
            "/",
            "\\",
            "=",
            "<",
            ">",
            ":",
            ";",
            ",",
            "\"",
            "&",
            "$",
            "#",
            "*",
            "|",
            "~",
            "`",
            "!",
            "{",
            "}",
            chr(0),
        );
        $data = str_replace($special_chars, ' ', $data);
        $data = trim($data);

        return $data;
    }
}
