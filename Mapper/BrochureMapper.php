<?php
// /public_html/cli/alto-sync/Mappers/BrochureMapper.php

namespace AltoSync\Mapper;

use SimpleXMLElement;
use AltoSync\Logger;

class BrochureMapper
{
    /**
     * Extract brochure PDF URLs (type=7) from Alto property XML.
     * Returns associative array ready for DB insertion.
     *
     * @param SimpleXMLElement $xml
     * @return array
     */
    public static function map(SimpleXMLElement $xml): array
    {
        $result = [
            'pro_pdf_file'  => '',
            'pro_pdf_file1' => '',
            'pro_pdf_file2' => '',
            'pro_pdf_file3' => '',
            'pro_pdf_file4' => '',
            'pro_pdf_file5' => '',
            'pro_pdf_file6' => '',
            'pro_pdf_file7' => '',
            'pro_pdf_file8' => '',
            'pro_pdf_file9' => '',
        ];

        if (!isset($xml->files->file)) {
            Logger::log('No <file> elements found in property XML.', 'DEBUG');
            return $result;
        }

        $pdfs = [];
        foreach ($xml->files->file as $file) {
            $type = (string) $file['type'];
            $url  = trim((string) $file->url);

            // Alto brochure PDFs are always type=7 and end with .pdf
            if ($type === '7' && stripos($url, '.pdf') !== false) {
                $pdfs[] = $url;
            }
        }

        Logger::log('BrochureMapper found ' . count($pdfs) . ' PDF(s).', 'DEBUG');

        if (empty($pdfs)) {
            return $result;
        }

        // Fill available OS Property PDF fields
        $result['pro_pdf_file'] = $pdfs[0];
        for ($i = 1; $i < min(10, count($pdfs)); $i++) {
            $result['pro_pdf_file' . $i] = $pdfs[$i] ?? '';
        }

        Logger::log('Mapped PDF brochure URLs: ' . print_r($result, true), 'DEBUG');
        return $result;
    }
}
