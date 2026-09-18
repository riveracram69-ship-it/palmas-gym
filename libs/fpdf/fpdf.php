<?php
/*******************************************************************************
* FPDF - Free PDF generation library for PHP                                   *
* Version: 1.86 (PHP 8.2+ Compatible)                                          *
* License: Freeware / Permissive Open Source                                   *
*******************************************************************************/

if (!class_exists('FPDF')) {
class FPDF
{
    protected $page;               // current page number
    protected $n;                  // current object number
    protected $offsets;            // array of object offsets
    protected $buffer;             // buffer holding in-memory PDF
    protected $pages;              // array containing pages
    protected $state;              // current document state
    protected $compress;           // compression flag
    protected $k;                  // scale factor (points per unit)
    protected $DefOrientation;     // default orientation
    protected $CurOrientation;     // current orientation
    protected $StdPageSizes;       // standard page sizes
    protected $DefPageSize;        // default page size
    protected $CurPageSize;        // current page size
    protected $CurRotation;        // current page rotation
    protected $PageInfo;           // page-related data
    protected $wPt, $hPt;          // dimensions of current page in points
    protected $w, $h;              // dimensions of current page in user units
    protected $lMargin;            // left margin
    protected $tMargin;            // top margin
    protected $rMargin;            // right margin
    protected $bMargin;            // page break margin
    protected $cMargin;            // cell margin
    protected $x, $y;              // current position in user units
    protected $lasth;              // height of last printed cell
    protected $LineWidth;          // line width in user units
    protected $fontpath;           // path containing fonts
    protected $CoreFonts;          // array of core font names
    protected $fonts;              // array of used fonts
    protected $FontFiles;          // array of font files
    protected $encodings;          // array of encodings
    protected $cmaps;              // array of ToUnicode CMaps
    protected $FontFamily;         // current font family
    protected $FontStyle;          // current font style
    protected $underline;          // underlining flag
    protected $CurrentFont;        // current font info
    protected $FontSizePt;         // current font size in points
    protected $FontSize;           // current font size in user units
    protected $DrawColor;          // commands for drawing color
    protected $FillColor;          // commands for filling color
    protected $TextColor;          // commands for text color
    protected $ColorFlag;          // indicates whether fill and text colors are different
    protected $WithAlpha;          // indicates whether alpha channel is used
    protected $ws;                 // word spacing
    protected $images;             // array of used images
    protected $PageLinks;          // array of links in pages
    protected $links;              // array of internal links
    protected $AutoPageBreak;      // automatic page breaking
    protected $PageBreakTrigger;   // threshold used to trigger page breaks
    protected $InHeader;           // flag set when processing header
    protected $InFooter;           // flag set when processing footer
    protected $AliasNbPages;       // alias for total number of pages
    protected $ZoomMode;           // zoom display mode
    protected $LayoutMode;         // layout display mode
    protected $metadata;           // document properties
    protected $PDFVersion;         // PDF version number

    function __construct($orientation='P', $unit='mm', $size='A4')
    {
        $this->_dochecks();
        $this->state = 0;
        $this->page = 0;
        $this->n = 2;
        $this->buffer = '';
        $this->pages = [];
        $this->PageInfo = [];
        $this->fonts = [];
        $this->FontFiles = [];
        $this->encodings = [];
        $this->cmaps = [];
        $this->images = [];
        $this->links = [];
        $this->InHeader = false;
        $this->InFooter = false;
        $this->lasth = 0;
        $this->FontFamily = '';
        $this->FontStyle = '';
        $this->FontSizePt = 12;
        $this->underline = false;
        $this->DrawColor = '0 G';
        $this->FillColor = '0 g';
        $this->TextColor = '0 g';
        $this->ColorFlag = false;
        $this->WithAlpha = false;
        $this->ws = 0;
        $this->offsets = [];

        // Core fonts
        $this->CoreFonts = [
            'courier'=>'Courier', 'courierB'=>'Courier-Bold', 'courierI'=>'Courier-Oblique', 'courierBI'=>'Courier-BoldOblique',
            'helvetica'=>'Helvetica', 'helveticaB'=>'Helvetica-Bold', 'helveticaI'=>'Helvetica-Oblique', 'helveticaBI'=>'Helvetica-BoldOblique',
            'times'=>'Times-Roman', 'timesB'=>'Times-Bold', 'timesI'=>'Times-Italic', 'timesBI'=>'Times-BoldItalic',
            'symbol'=>'Symbol', 'zapfdingbats'=>'ZapfDingbats'
        ];

        // Scale factor
        if ($unit=='pt') $this->k = 1;
        elseif ($unit=='mm') $this->k = 72/25.4;
        elseif ($unit=='cm') $this->k = 72/2.54;
        elseif ($unit=='in') $this->k = 72;
        else $this->Error('Incorrect unit: '.$unit);

        // Page sizes
        $this->StdPageSizes = [
            'a3'=>[841.89,1190.55], 'a4'=>[595.28,841.89], 'a5'=>[420.94,595.28],
            'letter'=>[612,792], 'legal'=>[612,1008]
        ];
        $size = $this->_getpagesize($size);
        $this->DefPageSize = $size;
        $this->CurPageSize = $size;

        // Page orientation
        $orientation = strtolower($orientation);
        if ($orientation=='p' || $orientation=='portrait') {
            $this->DefOrientation = 'P';
            $this->w = $size[0];
            $this->h = $size[1];
        } elseif ($orientation=='l' || $orientation=='landscape') {
            $this->DefOrientation = 'L';
            $this->w = $size[1];
            $this->h = $size[0];
        } else $this->Error('Incorrect orientation: '.$orientation);

        $this->CurOrientation = $this->DefOrientation;
        $this->wPt = $this->w*$this->k;
        $this->hPt = $this->h*$this->k;

        // Page rotation
        $this->CurRotation = 0;

        // Margins (1cm)
        $margin = 28.35/$this->k;
        $this->SetMargins($margin, $margin);
        $this->cMargin = $margin/10;
        $this->LineWidth = .567/$this->k;
        $this->SetAutoPageBreak(true, 2*$margin);
        $this->SetDisplayMode('default');
        $this->SetCompression(true);
        $this->PDFVersion = '1.4';
    }

    function SetMargins($left, $top, $right=null)
    {
        $this->lMargin = $left;
        $this->tMargin = $top;
        if ($right===null) $right = $left;
        $this->rMargin = $right;
    }

    function SetLeftMargin($margin)
    {
        $this->lMargin = $margin;
        if ($this->page>0 && $this->x<$margin) $this->x = $margin;
    }

    function SetTopMargin($margin)
    {
        $this->tMargin = $margin;
    }

    function SetRightMargin($margin)
    {
        $this->rMargin = $margin;
    }

    function SetAutoPageBreak($auto, $margin=0)
    {
        $this->AutoPageBreak = $auto;
        $this->bMargin = $margin;
        $this->PageBreakTrigger = $this->h-$margin;
    }

    function SetDisplayMode($zoom, $layout='default')
    {
        if ($zoom=='fullpage' || $zoom=='fullwidth' || $zoom=='real' || $zoom=='default' || !is_string($zoom))
            $this->ZoomMode = $zoom;
        else
            $this->Error('Incorrect zoom display mode: '.$zoom);
        if ($layout=='single' || $layout=='continuous' || $layout=='two' || $layout=='default')
            $this->LayoutMode = $layout;
        else
            $this->Error('Incorrect layout display mode: '.$layout);
    }

    function SetCompression($compress)
    {
        if (function_exists('gzcompress')) $this->compress = $compress;
        else $this->compress = false;
    }

    function SetTitle($title, $isUTF8=false)
    {
        $this->metadata['Title'] = $isUTF8 ? $title : $this->_UTF8toUTF16($title);
    }

    function SetAuthor($author, $isUTF8=false)
    {
        $this->metadata['Author'] = $isUTF8 ? $author : $this->_UTF8toUTF16($author);
    }

    function SetSubject($subject, $isUTF8=false)
    {
        $this->metadata['Subject'] = $isUTF8 ? $subject : $this->_UTF8toUTF16($subject);
    }

    function SetKeywords($keywords, $isUTF8=false)
    {
        $this->metadata['Keywords'] = $isUTF8 ? $keywords : $this->_UTF8toUTF16($keywords);
    }

    function SetCreator($creator, $isUTF8=false)
    {
        $this->metadata['Creator'] = $isUTF8 ? $creator : $this->_UTF8toUTF16($creator);
    }

    function AliasNbPages($alias='{nb}')
    {
        $this->AliasNbPages = $alias;
    }

    function Error($msg)
    {
        throw new \Exception('FPDF error: '.$msg);
    }

    function Open()
    {
        $this->state = 1;
    }

    function Close()
    {
        if ($this->state==3) return;
        if ($this->page==0) $this->AddPage();
        $this->InFooter = true;
        $this->Footer();
        $this->InFooter = false;
        $this->_endpage();
        $this->_enddoc();
    }

    function AddPage($orientation='', $size='', $rotation=0)
    {
        if ($this->state==0) $this->Open();
        $family = $this->FontFamily;
        $style = $this->FontStyle.($this->underline ? 'U' : '');
        $fontsize = $this->FontSizePt;
        $lw = $this->LineWidth;
        $dc = $this->DrawColor;
        $fc = $this->FillColor;
        $tc = $this->TextColor;
        $cf = $this->ColorFlag;
        if ($this->page>0) {
            $this->InFooter = true;
            $this->Footer();
            $this->InFooter = false;
            $this->_endpage();
        }
        $this->_beginpage($orientation, $size, $rotation);
        $this->_out('2 J');
        $this->LineWidth = $lw;
        $this->_out(sprintf('%.2F w', $lw*$this->k));
        if ($family) $this->SetFont($family, $style, $fontsize);
        $this->DrawColor = $dc;
        if ($dc!='0 G') $this->_out($dc);
        $this->FillColor = $fc;
        if ($fc!='0 g') $this->_out($fc);
        $this->TextColor = $tc;
        $this->ColorFlag = $cf;
        $this->InHeader = true;
        $this->Header();
        $this->InHeader = false;
        if ($this->LineWidth!=$lw) {
            $this->LineWidth = $lw;
            $this->_out(sprintf('%.2F w', $lw*$this->k));
        }
        if ($family) $this->SetFont($family, $style, $fontsize);
        if ($this->DrawColor!=$dc) {
            $this->DrawColor = $dc;
            $this->_out($dc);
        }
        if ($this->FillColor!=$fc) {
            $this->FillColor = $fc;
            $this->_out($fc);
        }
        $this->TextColor = $tc;
        $this->ColorFlag = $cf;
    }

    function Header() {}
    function Footer() {}

    function PageNo()
    {
        return $this->page;
    }

    function SetDrawColor($r, $g=null, $b=null)
    {
        if (($r==0 && $g==0 && $b==0) || $g===null)
            $this->DrawColor = sprintf('%.3F G', $r/255);
        else
            $this->DrawColor = sprintf('%.3F %.3F %.3F RG', $r/255, $g/255, $b/255);
        if ($this->page>0) $this->_out($this->DrawColor);
    }

    function SetFillColor($r, $g=null, $b=null)
    {
        if (($r==0 && $g==0 && $b==0) || $g===null)
            $this->FillColor = sprintf('%.3F g', $r/255);
        else
            $this->FillColor = sprintf('%.3F %.3F %.3F rg', $r/255, $g/255, $b/255);
        $this->ColorFlag = ($this->FillColor!=$this->TextColor);
        if ($this->page>0) $this->_out($this->FillColor);
    }

    function SetTextColor($r, $g=null, $b=null)
    {
        if (($r==0 && $g==0 && $b==0) || $g===null)
            $this->TextColor = sprintf('%.3F g', $r/255);
        else
            $this->TextColor = sprintf('%.3F %.3F %.3F rg', $r/255, $g/255, $b/255);
        $this->ColorFlag = ($this->FillColor!=$this->TextColor);
    }

    function GetStringWidth($s)
    {
        $s = (string)$s;
        $cw = &$this->CurrentFont['cw'];
        $w = 0;
        $l = strlen($s);
        for ($i=0; $i<$l; $i++) $w += $cw[$s[$i]] ?? 600;
        return $w*$this->FontSize/1000;
    }

    function SetLineWidth($width)
    {
        $this->LineWidth = $width;
        if ($this->page>0) $this->_out(sprintf('%.2F w', $width*$this->k));
    }

    function Line($x1, $y1, $x2, $y2)
    {
        $this->_out(sprintf('%.2F %.2F m %.2F %.2F l S', $x1*$this->k, ($this->h-$y1)*$this->k, $x2*$this->k, ($this->h-$y2)*$this->k));
    }

    function Rect($x, $y, $w, $h, $style='')
    {
        if ($style=='F') $op = 'f';
        elseif ($style=='FD' || $style=='DF') $op = 'B';
        else $op = 'S';
        $this->_out(sprintf('%.2F %.2F %.2F %.2F re %s', $x*$this->k, ($this->h-$y)*$this->k, $w*$this->k, -$h*$this->k, $op));
    }

    function SetFont($family, $style='', $size=0)
    {
        if ($family=='') $family = $this->FontFamily;
        else $family = strtolower($family);
        $style = strtoupper($style);
        if (strpos($style, 'U')!==false) {
            $this->underline = true;
            $style = str_replace('U', '', $style);
        } else $this->underline = false;
        if ($style=='IB') $style = 'BI';
        if ($size==0) $size = $this->FontSizePt;

        if ($this->FontFamily==$family && $this->FontStyle==$style && $this->FontSizePt==$size) return;

        $fontkey = $family.$style;
        if (!isset($this->fonts[$fontkey])) {
            if (isset($this->CoreFonts[$fontkey])) {
                if (!isset($this->fonts[$fontkey])) {
                    $this->fonts[$fontkey] = [
                        'i' => count($this->fonts)+1,
                        'type' => 'core',
                        'name' => $this->CoreFonts[$fontkey],
                        'up' => -100,
                        'ut' => 50,
                        'cw' => $this->_getCoreFontWidths($this->CoreFonts[$fontkey])
                    ];
                }
            } else {
                $this->Error('Undefined font: '.$family.' '.$style);
            }
        }
        $this->FontFamily = $family;
        $this->FontStyle = $style;
        $this->FontSizePt = $size;
        $this->FontSize = $size/$this->k;
        $this->CurrentFont = &$this->fonts[$fontkey];
        if ($this->page>0) $this->_out(sprintf('BT /F%d %.2F Tf ET', $this->CurrentFont['i'], $this->FontSizePt));
    }

    function SetFontSize($size)
    {
        if ($this->FontSizePt==$size) return;
        $this->FontSizePt = $size;
        $this->FontSize = $size/$this->k;
        if ($this->page>0 && isset($this->CurrentFont['i']))
            $this->_out(sprintf('BT /F%d %.2F Tf ET', $this->CurrentFont['i'], $this->FontSizePt));
    }

    function Text($x, $y, $txt)
    {
        $txt = (string)$txt;
        $s = sprintf('BT %.2F %.2F Td (%s) Tj ET', $x*$this->k, ($this->h-$y)*$this->k, $this->_escape($txt));
        if ($this->underline && $txt!='') $s .= ' '.$this->_dounderline($x, $y, $txt);
        if ($this->ColorFlag) $s = 'q '.$this->TextColor.' '.$s.' Q';
        $this->_out($s);
    }

    function Cell($w, $h=0, $txt='', $border=0, $ln=0, $align='', $fill=false, $link='')
    {
        $k = $this->k;
        if ($this->y+$h>$this->PageBreakTrigger && !$this->InHeader && !$this->InFooter && $this->AcceptPageBreak()) {
            $x = $this->x;
            $ws = $this->ws;
            if ($ws>0) {
                $this->ws = 0;
                $this->_out('0 Tw');
            }
            $this->AddPage($this->CurOrientation, $this->CurPageSize, $this->CurRotation);
            $this->x = $x;
            if ($ws>0) {
                $this->ws = $ws;
                $this->_out(sprintf('%.3F Tw', $ws*$k));
            }
        }
        if ($w==0) $w = $this->w-$this->rMargin-$this->x;
        $s = '';
        if ($fill || $border==1) {
            if ($fill) $op = ($border==1) ? 'B' : 'f';
            else $op = 'S';
            $s = sprintf('%.2F %.2F %.2F %.2F re %s ', $this->x*$k, ($this->h-$this->y)*$k, $w*$k, -$h*$k, $op);
        }
        if (is_string($border)) {
            $x = $this->x;
            $y = $this->y;
            if (strpos($border, 'L')!==false) $s .= sprintf('%.2F %.2F m %.2F %.2F l S ', $x*$k, ($this->h-$y)*$k, $x*$k, ($this->h-($y+$h))*$k);
            if (strpos($border, 'T')!==false) $s .= sprintf('%.2F %.2F m %.2F %.2F l S ', $x*$k, ($this->h-$y)*$k, ($x+$w)*$k, ($this->h-$y)*$k);
            if (strpos($border, 'R')!==false) $s .= sprintf('%.2F %.2F m %.2F %.2F l S ', ($x+$w)*$k, ($this->h-$y)*$k, ($x+$w)*$k, ($this->h-($y+$h))*$k);
            if (strpos($border, 'B')!==false) $s .= sprintf('%.2F %.2F m %.2F %.2F l S ', $x*$k, ($this->h-($y+$h))*$k, ($x+$w)*$k, ($this->h-($y+$h))*$k);
        }
        $txt = (string)$txt;
        if ($txt!=='') {
            if ($align=='R') $dx = $w-$this->cMargin-$this->GetStringWidth($txt);
            elseif ($align=='C') $dx = ($w-$this->GetStringWidth($txt))/2;
            else $dx = $this->cMargin;
            if ($this->ColorFlag) $s .= 'q '.$this->TextColor.' ';
            $s .= sprintf('BT %.2F %.2F Td (%s) Tj ET', ($this->x+$dx)*$k, ($this->h-($this->y+.5*$h+.3*$this->FontSize))*$k, $this->_escape($txt));
            if ($this->underline) $s .= ' '.$this->_dounderline($this->x+$dx, $this->y+.5*$h+.3*$this->FontSize, $txt);
            if ($this->ColorFlag) $s .= ' Q';
        }
        if ($s) $this->_out($s);
        $this->lasth = $h;
        if ($ln>0) {
            $this->y += $h;
            if ($ln==1) $this->x = $this->lMargin;
        } else $this->x += $w;
    }

    function Ln($h=null)
    {
        $this->x = $this->lMargin;
        if ($h===null) $this->y += $this->lasth;
        else $this->y += $h;
    }

    function GetX() { return $this->x; }
    function SetX($x) { if ($x>=0) $this->x = $x; else $this->x = $this->w+$x; }
    function GetY() { return $this->y; }
    function SetY($y, $resetX=true)
    {
        if ($y>=0) $this->y = $y;
        else $this->y = $this->h+$y;
        if ($resetX) $this->x = $this->lMargin;
    }
    function SetXY($x, $y) { $this->SetY($y, false); $this->SetX($x); }

    function Output($dest='', $name='', $isUTF8=false)
    {
        $this->Close();
        if (strlen($name)==0) {
            $name = 'doc.pdf';
            $dest = 'I';
        }
        $dest = strtoupper($dest);
        if ($dest=='') $dest = 'I';

        switch ($dest) {
            case 'I':
                $this->_checkoutput();
                if (PHP_SAPI!='cli') {
                    header('Content-Type: application/pdf');
                    header('Content-Disposition: inline; '.$this->_httpencode('filename', $name, $isUTF8));
                    header('Cache-Control: private, max-age=0, must-revalidate');
                    header('Pragma: public');
                }
                echo $this->buffer;
                break;
            case 'D':
                $this->_checkoutput();
                header('Content-Type: application/x-download');
                header('Content-Disposition: attachment; '.$this->_httpencode('filename', $name, $isUTF8));
                header('Cache-Control: private, max-age=0, must-revalidate');
                header('Pragma: public');
                echo $this->buffer;
                break;
            case 'F':
                $f = fopen($name, 'wb');
                if (!$f) $this->Error('Unable to create output file: '.$name);
                fwrite($f, $this->buffer, strlen($this->buffer));
                fclose($f);
                break;
            case 'S':
                return $this->buffer;
            default:
                $this->Error('Incorrect output destination: '.$dest);
        }
        return '';
    }

    function AcceptPageBreak()
    {
        return $this->AutoPageBreak;
    }

    // ── Internal methods ───────────────────────────────────────────────
    protected function _dochecks()
    {
        if (sprintf('%.1F', 1.0)!='1.0') setlocale(LC_NUMERIC, 'C');
    }

    protected function _getpagesize($size)
    {
        if (is_string($size)) {
            $size = strtolower($size);
            if (!isset($this->StdPageSizes[$size])) $this->Error('Unknown page size: '.$size);
            $a = $this->StdPageSizes[$size];
            return [$a[0]/$this->k, $a[1]/$this->k];
        } else {
            if ($size[0]>$size[1]) return [$size[1], $size[0]];
            else return $size;
        }
    }

    protected function _beginpage($orientation, $size, $rotation)
    {
        $this->page++;
        $this->pages[$this->page] = '';
        $this->state = 2;
        $this->x = $this->lMargin;
        $this->y = $this->tMargin;
        $this->FontFamily = '';
        if (!$orientation) $orientation = $this->DefOrientation;
        else $orientation = strtoupper($orientation[0]);
        if (!$size) $size = $this->DefPageSize;
        else $size = $this->_getpagesize($size);
        if ($orientation!=$this->CurOrientation || $size[0]!=$this->CurPageSize[0] || $size[1]!=$this->CurPageSize[1]) {
            if ($orientation=='P') {
                $this->w = $size[0];
                $this->h = $size[1];
            } else {
                $this->w = $size[1];
                $this->h = $size[0];
            }
            $this->wPt = $this->w*$this->k;
            $this->hPt = $this->h*$this->k;
            $this->PageBreakTrigger = $this->h-$this->bMargin;
            $this->CurOrientation = $orientation;
            $this->CurPageSize = $size;
        }
        if ($orientation!=$this->DefOrientation || $size[0]!=$this->DefPageSize[0] || $size[1]!=$this->DefPageSize[1])
            $this->PageInfo[$this->page]['size'] = [$this->wPt, $this->hPt];
        if ($rotation!=0) {
            if ($rotation%90!=0) $this->Error('Incorrect rotation value: '.$rotation);
            $this->PageInfo[$this->page]['rotation'] = $rotation;
        }
    }

    protected function _endpage()
    {
        $this->state = 1;
    }

    protected function _escape($s)
    {
        return str_replace(['\\', ')', '(', "\r"], ['\\\\', '\\)', '\\(', ''], $s);
    }

    protected function _dounderline($x, $y, $txt)
    {
        $up = $this->CurrentFont['up'];
        $ut = $this->CurrentFont['ut'];
        $w = $this->GetStringWidth($txt)+$this->ws*substr_count($txt, ' ');
        return sprintf('%.2F %.2F %.2F %.2F re f', $x*$this->k, ($this->h-($y-$up/1000*$this->FontSize))*$this->k, $w*$this->k, -$ut/1000*$this->FontSizePt);
    }

    protected function _out($s)
    {
        if ($this->state==2) $this->pages[$this->page] .= $s."\n";
        elseif ($this->state==1) $this->_put($s);
        elseif ($this->state==0) $this->Error('No page has been added yet');
        elseif ($this->state==3) $this->Error('The document is closed');
    }

    protected function _put($s)
    {
        $this->buffer .= $s."\n";
    }

    protected function _enddoc()
    {
        $this->state = 3;
        $this->_putheader();
        $this->_putpages();
        $this->_putresources();
        // Info
        $this->_newobj();
        $this->_put('<<');
        $this->_putinfo();
        $this->_put('>>');
        $this->_put('endobj');
        // Catalog
        $this->_newobj();
        $this->_put('<<');
        $this->_putcatalog();
        $this->_put('>>');
        $this->_put('endobj');
        // Cross-ref
        $offset = strlen($this->buffer);
        $this->_put('xref');
        $this->_put('0 '.($this->n+1));
        $this->_put('0000000000 65535 f ');
        for ($i=1; $i<=$this->n; $i++)
            $this->_put(sprintf('%010d 00000 n ', $this->offsets[$i]));
        // Trailer
        $this->_put('trailer');
        $this->_put('<<');
        $this->_puttrailer();
        $this->_put('>>');
        $this->_put('startxref');
        $this->_put($offset);
        $this->_put('%%EOF');
    }

    protected function _putheader()
    {
        $this->buffer = "%PDF-".$this->PDFVersion."\n";
    }

    protected function _putpages()
    {
        $nb = $this->page;
        for ($n=1; $n<=$nb; $n++) {
            $this->_newobj();
            $this->_put('<</Type /Page');
            $this->_put('/Parent 1 0 R');
            if (isset($this->PageInfo[$n]['size']))
                $this->_put(sprintf('/MediaBox [0 0 %.2F %.2F]', $this->PageInfo[$n]['size'][0], $this->PageInfo[$n]['size'][1]));
            if (isset($this->PageInfo[$n]['rotation']))
                $this->_put('/Rotate '.$this->PageInfo[$n]['rotation']);
            $this->_put('/Resources 2 0 R');
            $this->_put('/Contents '.($this->n+1).' 0 R>>');
            $this->_put('endobj');
            // Page content
            $p = ($this->compress) ? gzcompress($this->pages[$n]) : $this->pages[$n];
            $this->_newobj();
            $this->_put('<<'.($this->compress ? '/Filter /FlateDecode ' : '').'/Length '.strlen($p).'>>');
            $this->_putstream($p);
            $this->_put('endobj');
        }
        // Pages root
        $this->offsets[1] = strlen($this->buffer);
        $this->_put('1 0 obj');
        $this->_put('<</Type /Pages');
        $kids = '/Kids [';
        for ($n=1; $n<=$nb; $n++) $kids .= (3+2*($n-1)).' 0 R ';
        $this->_put($kids.']');
        $this->_put('/Count '.$nb);
        $this->_put(sprintf('/MediaBox [0 0 %.2F %.2F]', $this->DefPageSize[0]*$this->k, $this->DefPageSize[1]*$this->k));
        $this->_put('>>');
        $this->_put('endobj');
    }

    protected function _putresources()
    {
        $this->_putfonts();
        $this->_newobj(2);
        $this->_put('<<');
        $this->_put('/ProcSet [/PDF /Text /ImageB /ImageC /ImageI]');
        $this->_put('/Font <<');
        foreach ($this->fonts as $font) $this->_put('/F'.$font['i'].' '.$font['n'].' 0 R');
        $this->_put('>>');
        $this->_put('>>');
        $this->_put('endobj');
    }

    protected function _putfonts()
    {
        foreach ($this->fonts as $k=>$font) {
            $this->_newobj();
            $this->fonts[$k]['n'] = $this->n;
            $name = $font['name'];
            $this->_put('<</Type /Font');
            $this->_put('/BaseFont /'.$name);
            $this->_put('/Subtype /Type1');
            if ($name!='Symbol' && $name!='ZapfDingbats') $this->_put('/Encoding /WinAnsiEncoding');
            $this->_put('>>');
            $this->_put('endobj');
        }
    }

    protected function _putinfo()
    {
        $this->_put('/Producer '.$this->_textstring('Palmas Elite Gym ERP Engine'));
        if (!empty($this->metadata['Title'])) $this->_put('/Title '.$this->_textstring($this->metadata['Title']));
        if (!empty($this->metadata['Subject'])) $this->_put('/Subject '.$this->_textstring($this->metadata['Subject']));
        if (!empty($this->metadata['Author'])) $this->_put('/Author '.$this->_textstring($this->metadata['Author']));
        $this->_put('/CreationDate '.$this->_textstring('D:'.@date('YmdHis')));
    }

    protected function _putcatalog()
    {
        $this->_put('/Type /Catalog');
        $this->_put('/Pages 1 0 R');
    }

    protected function _puttrailer()
    {
        $this->_put('/Size '.($this->n+1));
        $this->_put('/Root '.$this->n.' 0 R');
        $this->_put('/Info '.($this->n-1).' 0 R');
    }

    protected function _newobj($n=null)
    {
        if ($n===null) $n = ++$this->n;
        $this->offsets[$n] = strlen($this->buffer);
        $this->_put($n.' 0 obj');
    }

    protected function _putstream($data)
    {
        $this->_put('stream');
        $this->_put($data);
        $this->_put('endstream');
    }

    protected function _textstring($s)
    {
        return '('.$this->_escape($s).')';
    }

    protected function _checkoutput()
    {
        if (PHP_SAPI!='cli') {
            if (headers_sent($file, $line))
                $this->Error("Some data has already been output, can't send PDF file (output started at $file:$line)");
        }
    }

    protected function _httpencode($param, $value, $isUTF8)
    {
        return $param.'="'.rawurlencode($value).'"';
    }

    protected function _UTF8toUTF16($s)
    {
        return "\xFE\xFF".mb_convert_encoding($s, 'UTF-16BE', 'UTF-8');
    }

    protected function _getCoreFontWidths($font)
    {
        // Standard approximations for Type1 core font characters
        $cw = array_fill(0, 256, 500);
        for ($i=32; $i<=126; $i++) {
            $c = chr($i);
            if (in_array($c, ['i', 'l', 'j', 't', '!', '|', ':', ';', ',', '.', '\''])) $cw[$c] = 278;
            elseif (in_array($c, ['m', 'w', 'M', 'W', '@', '%'])) $cw[$c] = 850;
            elseif (ctype_upper($c)) $cw[$c] = 680;
            elseif (in_array($c, ['f', 'r', 's', 'I'])) $cw[$c] = 350;
            else $cw[$c] = 556;
        }
        return $cw;
    }
}
}
