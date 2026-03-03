<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Settings
    |--------------------------------------------------------------------------
    |
    | Set some default values. It is possible to add all defines that can be set
    | in dompdf_config.inc.php. You can also override the entire config file.
    |
    */
    'show_warnings' => false,   // Throw an Exception on warnings from dompdf
    'public_path' => null,      // Use Laravel's default public_path()

    /*
     * Dejavu Sans font is missing glyphs for converted entities, turn it off if you need to see € and £.
     */
    'convert_entities' => false,

    'options' => [
        /*
         * The location of the DOMPDF font directory
         *
         * The combination of these two variables (in addition to the font
         * cache directory) must result in a valid path to the font files.
         *
         * Examples using public_path():
         * - /var/www/html/project/storage/fonts/ if /var/www/html/project/public is public_path()
         * - /var/www/html/project/public/storage/fonts/ if /var/www/html/project/public is public_path()
         *
         * Backslash variation (windows):
         * - C:\inetpub\wwwroot\project\storage/fonts/ if C:\inetpub\wwwroot\project\public is public_path()
         */
        'font_dir' => storage_path('fonts'), // advised by dompdf

        /*
         * The location of the DOMPDF font cache directory
         *
         * This directory contains the cached font metrics for the fonts used by DOMPDF.
         * This directory can be the same as font_dir
         *
         * Note: This directory must exist and be writable by the webserver process.
         * *Please note the trailing slash.*
         *
         * Examples using storage_path():
         * - /var/www/html/project/storage/fonts/ if /var/www/html/project/storage is storage_path()
         * - /var/www/html/project/storage/fonts/ if /var/www/html/project/storage is storage_path()
         *
         * Backslash variation (windows):
         * - C:\inetpub\wwwroot\project\storage/fonts/ if C:\inetpub\wwwroot\project\storage is storage_path()
         */
        'font_cache' => storage_path('fonts'),

        /*
         * The location of a temporary directory.
         *
         * The directory specified must be writeable by the webserver process.
         * The temporary directory is required to download remote images and when
         * using the PFDLib back end.
         */
        'temp_dir' => sys_get_temp_dir(),

        /*
         * ==== IMPORTANT ====
         *
         * dompdf "chroot" the application script execution to a temporary directory
         * when processing untrusted HTML. Since dompdf v0.6.0, this is done for
         * security reasons. The chroot directory must be empty and different from
         * the system temporary directory.
         *
         * https://github.com/dompdf/dompdf/wiki/3-Configuration#chroot
         *
         * - chroot: Applicable only when rendering HTML
         *   If true, temporarily change the root directory to the temporary directory
         *   when processing HTML files. This prevents dompdf from accessing system or
         *   other files on the server.  All files that are accessed must be included in
         *   the HTML or loaded via the file:// protocol.
         *   Enabling this may slow down dompdf slightly. The temporary directory must
         *   be empty and different from the system temporary directory.
         * - chroot: Solo aplicable al renderizar HTML
         *   Si es true, cambia temporalmente el directorio raíz al directorio temporal
         *   al procesar archivos HTML. Esto previene que dompdf acceda a archivos del sistema
         *   u otros archivos en el servidor. Todos los archivos que se acceden deben estar incluidos
         *   en el HTML o cargados vía el protocolo file://.
         *   Habilitar esto puede ralentizar dompdf ligeramente. El directorio temporal debe
         *   estar vacío y ser diferente del directorio temporal del sistema.
         */
        'chroot' => realpath(base_path()),

        /*
         * Whether to enable font subsetting or not.
         */
        'enable_font_subsetting' => false,

        /*
         * The PDF rendering backend to use
         *
         * Valid options are 'PDFLib', 'CPDF' (the bundled R&OS PDF class), 'GD' and
         * 'auto'. 'auto' will look for PDFLib and use it if found, or if not it will
         * fall back to CPDF. 'GD' renders PDFs to graphic files. {@link
         * Canvas_Factory} ultimately determines which rendering class to use.
         */
        'pdf_backend' => 'CPDF',

        /*
         * PDFlib license key
         *
         * If you are using a licensed, commercial version of PDFlib, specify
         * your license key here. If you are using PDFlib-Lite or are evaluating
         * the software, comment out this line.
         *
         * @see http://www.pdflib.com
         *
         * If pdflib present in web server and auto or selected explicitely above,
         * a real license code must exist!
         */
        //"pdflib_license" => "your license key here",

        /*
         * html target media view which should be rendered into pdf.
         * List of types and parsing rules for future extensions:
         * http://www.w3.org/TR/REC-html40/types.html
         *   screen, tty, tv, projection, handheld, print, braille, aural, all
         * Note: aural is deprecated in CSS 2.1 because it is replaced by speech in CSS 3.
         * Note, even though the generated pdf file is intended for print output,
         * the desired content might be different (e.g. screen or projection view of html file).
         * Therefore allow specification of content here.
         */
        'default_media_type' => 'screen',

        /*
         * The default paper size.
         *
         * The North America standard is "letter"; other countries/continents generally use "a4"
         *
         * @see CPDF_Adapter::PAPER_SIZES for valid sizes ('letter', 'legal', 'A4', etc.)
         */
        'default_paper_size' => 'a4',

        /*
         * The default paper orientation.
         *
         * The orientation of the page (portrait or landscape).
         *
         * @see CPDF_Adapter::ORIENTATIONS for valid orientations ('portrait', 'landscape')
         */
        'default_paper_orientation' => 'portrait',

        /*
         * The default font family
         *
         * Used if no suitable fonts can be found. This must exist in the font folder.
         * @* @see CPDF_Adapter::DEFAULT_FONT
         */
        'default_font' => 'serif',

        /*
         * Image DPI setting
         *
         * This setting determines the default DPI setting for images (72 dpi is standard).
         *
         * @see CPDF_Adapter::DPI
         */
        'dpi' => 96,

        /*
         * Font height ratio
         *
         * @see CPDF_Adapter::FONT_HEIGHT_RATIO
         */
        'font_height_ratio' => 1.1,

        /*
         * Enable embedded PHP
         *
         * If this setting is set to true then DOMPDF will automatically evaluate
         * embedded PHP contained within <script type="text/php"> ... </script> tags.
         *
         * Enabling this for documents you do not trust (e.g. arbitrary remote html
         * pages) is a security risk.  Set this option to false if you wish to process
         * untrusted documents.
         *
         * @see CPDF_Adapter::ENABLE_PHP
         */
        'enable_php' => false,

        /*
         * Enable inline Javascript
         *
         * If this setting is set to true then DOMPDF will automatically insert
         * JavaScript code contained within <script type="text/javascript"> ... </script> tags.
         *
         * @see CPDF_Adapter::ENABLE_JAVASCRIPT
         */
        'enable_javascript' => true,

        /*
         * Enable remote file access
         *
         * If this setting is set to true, DOMPDF will access remote sites for
         * images and CSS files as required.
         * This is required for part of test case www/test/image_variants.html through www/examples.html
         *
         * @see CPDF_Adapter::ENABLE_REMOTE
         */
        'enable_remote' => true,

        /*
         * A ratio applied to the fonts height to be more like browsers' line height
         */
        'line_height_ratio' => 1.1,

        /*
         * Use the HTML5 Lib parser
         *
         * @see CPDF_Adapter::ENABLE_HTML5PARSER
         */
        'enable_html5_parser' => true,
    ],

];
