<?php
namespace modules\filters;

use Craft;
use craft\base\Component;
use Twig\Extension\AbstractExtension;
use Twig\TwigFilter;

class FileSizeFilter extends AbstractExtension
{
    public function getName(): string
    {
        return 'File Size Filter';
    }

    public function getFilters(): array
    {
        return [
            new TwigFilter('filesizeformat', [$this, 'formatFileSize']),
        ];
    }

    /**
     * Format bytes to human readable file size with localization support
     * 
     * @param int $bytes File size in bytes
     * @param string $language Language code ('en' or 'fr')
     * @param bool $binary Use binary (1024) or decimal (1000) calculation
     * @return string Formatted file size
     */
    public function formatFileSize($bytes = 0, string $language = 'auto', bool $binary = true): string
    {
           // Handle null values before they reach the type-hinted method
           if ($bytes === null) {
            $bytes = 0;
        }
        // Detect language based on Craft's locale if not specified
        if ($language === 'auto') {
            $language = substr(Craft::$app->language, 0, 2);
        }

        // Set up units based on language
        $units = $this->getUnits($language, $binary);
        
        // Set the base for conversion (binary: 1024, decimal: 1000)
        $base = $binary ? 1024 : 1000;
        
        // Handle zero bytes case
        if ($bytes === 0) {
            return "0 " . $units[0];
        }
        
        // Calculate appropriate unit
        $power = floor(log($bytes, $base));
        $power = min($power, count($units) - 1); // Ensure we don't exceed available units
        
        // Calculate the value with the selected unit
        $value = $bytes / pow($base, $power);
        
        // Format with two decimal places
        $formatted = number_format($value, 2, $this->getDecimalSeparator($language), $this->getThousandsSeparator($language));
        
        // Remove trailing zeros and decimal point if needed
        $formatted = rtrim(rtrim($formatted, '0'), $this->getDecimalSeparator($language));
        
        return $formatted . ' ' . $units[$power];
    }
    
    /**
     * Get the appropriate units based on language and system
     */
    private function getUnits(string $language, bool $binary): array
    {
        if ($language === 'fr') {
            return $binary 
                ? ['o', 'Ko', 'Mo', 'Go', 'To', 'Po', 'Eo', 'Zo', 'Yo'] 
                : ['o', 'Ko', 'Mo', 'Go', 'To', 'Po', 'Eo', 'Zo', 'Yo'];
        } else {
            return $binary 
                ? ['B', 'KiB', 'MiB', 'GiB', 'TiB', 'PiB', 'EiB', 'ZiB', 'YiB'] 
                : ['B', 'KB', 'MB', 'GB', 'TB', 'PB', 'EB', 'ZB', 'YB'];
        }
    }
    
    /**
     * Get the decimal separator based on language
     */
    private function getDecimalSeparator(string $language): string
    {
        return $language === 'fr' ? ',' : '.';
    }
    
    /**
     * Get the thousands separator based on language
     */
    private function getThousandsSeparator(string $language): string
    {
        return $language === 'fr' ? ' ' : ',';
    }
}