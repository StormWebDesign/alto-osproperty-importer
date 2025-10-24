<?php
namespace AltoSync\Mapper;

use AltoSync\Logger;

class BrochureMapper
{
    /**
     * Extract up to 10 brochure PDF URLs directly from the <files> section.
     * Returns associative array with keys pro_pdf_file ... pro_pdf_file9
     */
    public static function extractPdfUrls(\SimpleXMLElement $propertyXmlObject): array
    {
        $pdfUrls = [];

        if (isset($propertyXmlObject->files) && $propertyXmlObject->files->file) {
            foreach ($propertyXmlObject->files->file as $fileNode) {
                $fileUrl = (string)$fileNode->url;
                $ext = strtolower(pathinfo($fileUrl, PATHINFO_EXTENSION));

                if ($ext === 'pdf' && !in_array($fileUrl, $pdfUrls, true)) {
                    $pdfUrls[] = $fileUrl;
                }
            }
        }

        // Fill up to 10 placeholders (pro_pdf_file...pro_pdf_file9)
        $result = [];
        for ($i = 0; $i <= 9; $i++) {
            $key = $i === 0 ? 'pro_pdf_file' : 'pro_pdf_file' . $i;
            $result[$key] = $pdfUrls[$i] ?? '';
        }

        Logger::log('BrochureMapper found ' . count($pdfUrls) . ' PDF(s).', 'DEBUG');
        return $result;
    }
}
