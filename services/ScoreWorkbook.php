<?php
namespace CmuLms;
// Small, dependency-free Open XML exporter for the score report's tabular data.
final class ScoreWorkbook {
 private static function xml(string $s):string {return htmlspecialchars(preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F]/','',$s),ENT_XML1|ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8');}
 private static function column(int $n):string {$s='';while($n>0){$n--;$s=chr(65+$n%26).$s;$n=intdiv($n,26);}return $s;}
 public static function write(string $path,array $sheets):void {
  $zip=new \ZipArchive();if($zip->open($path,\ZipArchive::CREATE|\ZipArchive::OVERWRITE)!==true)throw new \RuntimeException('Cannot create Excel export.');
  $ns='http://schemas.openxmlformats.org/spreadsheetml/2006/main';$rel='http://schemas.openxmlformats.org/officeDocument/2006/relationships';
  $types='<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"><Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/><Default Extension="xml" ContentType="application/xml"/><Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/><Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/>';
  $book='<workbook xmlns="'.$ns.'" xmlns:r="'.$rel.'"><sheets>';$rels='<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">';$i=0;
  foreach($sheets as $name=>$rows){$i++;$width=count($rows[0]);if($width>16384)throw new \RuntimeException('Too many columns; export one academic term at a time.');$last=self::column($width).count($rows);
   $book.='<sheet name="'.self::xml($name).'" sheetId="'.$i.'" r:id="rId'.$i.'"/>';$rels.='<Relationship Id="rId'.$i.'" Type="'.$rel.'/worksheet" Target="worksheets/sheet'.$i.'.xml"/>';$types.='<Override PartName="/xl/worksheets/sheet'.$i.'.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>';
   $sheet='<worksheet xmlns="'.$ns.'"><dimension ref="A1:'.$last.'"/><sheetViews><sheetView workbookViewId="0"><pane xSplit="4" ySplit="1" topLeftCell="E2" activePane="bottomRight" state="frozen"/></sheetView></sheetViews><cols><col min="1" max="2" width="23" customWidth="1"/><col min="3" max="3" width="15" customWidth="1"/><col min="4" max="'.$width.'" width="24" customWidth="1"/></cols><sheetData>';
   foreach($rows as $r=>$values){$sheet.='<row r="'.($r+1).'"'.($r===0?' ht="42" customHeight="1"':'').'>';foreach($values as $c=>$v){$ref=self::column($c+1).($r+1);$style=$r===0?1:0;if($v===null)$sheet.='<c r="'.$ref.'"/>';elseif(is_int($v)||is_float($v))$sheet.='<c r="'.$ref.'" s="'.$style.'"><v>'.$v.'</v></c>';else $sheet.='<c r="'.$ref.'" s="'.$style.'" t="inlineStr"><is><t xml:space="preserve">'.self::xml((string)$v).'</t></is></c>';}$sheet.='</row>';}
   $sheet.='</sheetData><autoFilter ref="A1:'.$last.'"/></worksheet>';$zip->addFromString('xl/worksheets/sheet'.$i.'.xml',$sheet);
  }
  $rels.='<Relationship Id="styles" Type="'.$rel.'/styles" Target="styles.xml"/></Relationships>';
  $zip->addFromString('[Content_Types].xml',$types.'</Types>');$zip->addFromString('xl/workbook.xml',$book.'</sheets></workbook>');$zip->addFromString('xl/_rels/workbook.xml.rels',$rels);
  $zip->addFromString('_rels/.rels','<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="workbook" Type="'.$rel.'/officeDocument" Target="xl/workbook.xml"/></Relationships>');
  $zip->addFromString('xl/styles.xml','<styleSheet xmlns="'.$ns.'"><fonts count="2"><font><sz val="11"/><name val="Calibri"/></font><font><b/><color rgb="FFFFFFFF"/><sz val="11"/><name val="Calibri"/></font></fonts><fills count="3"><fill><patternFill patternType="none"/></fill><fill><patternFill patternType="gray125"/></fill><fill><patternFill patternType="solid"><fgColor rgb="FF124B3F"/><bgColor indexed="64"/></patternFill></fill></fills><borders count="1"><border/></borders><cellStyleXfs count="1"><xf numFmtId="0" fontId="0" fillId="0" borderId="0"/></cellStyleXfs><cellXfs count="2"><xf numFmtId="0" fontId="0" fillId="0" borderId="0" xfId="0"/><xf numFmtId="0" fontId="1" fillId="2" borderId="0" xfId="0" applyAlignment="1"><alignment wrapText="1" vertical="center"/></xf></cellXfs><cellStyles count="1"><cellStyle name="Normal" xfId="0" builtinId="0"/></cellStyles></styleSheet>');
  if(!$zip->close())throw new \RuntimeException('Cannot finish Excel export.');
 }
}
