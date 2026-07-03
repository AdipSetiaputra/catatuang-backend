<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Barryvdh\DomPDF\Facade\Pdf;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use PhpOffice\PhpWord\PhpWord;
use PhpOffice\PhpWord\IOFactory;

class ExportController extends Controller
{
    public function pdf(Request $request)
    {
        $transactions = $request->user()->transactions()->with('wallet')->whereDate('created_at', today())->get();
        
        $html = '<h1>Rekap Transaksi Hari Ini</h1>';
        $html .= '<table border="1" cellpadding="5" cellspacing="0" style="width:100%; border-collapse:collapse;">';
        $html .= '<thead><tr style="background-color: #f3f4f6;"><th>Tipe</th><th>Kategori</th><th>Nominal</th><th>Dompet</th><th>Catatan</th></tr></thead><tbody>';
        
        $totalMasuk = 0;
        $totalKeluar = 0;
        foreach ($transactions as $tx) {
            $html .= '<tr>';
            $html .= '<td>' . ucfirst($tx->type) . '</td>';
            $html .= '<td>' . $tx->category . '</td>';
            $html .= '<td>Rp ' . number_format($tx->amount, 0, ',', '.') . '</td>';
            $html .= '<td>' . ($tx->wallet->name ?? '-') . '</td>';
            $html .= '<td>' . ($tx->note ?? '-') . '</td>';
            $html .= '</tr>';
            if ($tx->type === 'masuk') $totalMasuk += $tx->amount;
            else $totalKeluar += $tx->amount;
        }
        $html .= '</tbody></table>';
        $html .= '<br><p><strong>Total Pemasukan:</strong> Rp ' . number_format($totalMasuk, 0, ',', '.') . '</p>';
        $html .= '<p><strong>Total Pengeluaran:</strong> Rp ' . number_format($totalKeluar, 0, ',', '.') . '</p>';

        $pdf = Pdf::loadHTML($html);
        return $pdf->download('rekap.pdf');
    }

    public function excel(Request $request)
    {
        $transactions = $request->user()->transactions()->with('wallet')->whereDate('created_at', today())->get();
        
        $spreadsheet = new Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet();
        
        $sheet->setCellValue('A1', 'Tipe');
        $sheet->setCellValue('B1', 'Kategori');
        $sheet->setCellValue('C1', 'Nominal');
        $sheet->setCellValue('D1', 'Dompet');
        $sheet->setCellValue('E1', 'Catatan');
        
        // Style headers
        $sheet->getStyle('A1:E1')->getFont()->setBold(true);
        $sheet->getStyle('A1:E1')->getFill()->setFillType(\PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID)->getStartColor()->setARGB('FFF3F4F6');

        $row = 2;
        $totalMasuk = 0;
        $totalKeluar = 0;
        foreach ($transactions as $tx) {
            $sheet->setCellValue('A' . $row, ucfirst($tx->type));
            $sheet->setCellValue('B' . $row, $tx->category);
            $sheet->setCellValue('C' . $row, $tx->amount);
            $sheet->setCellValue('D' . $row, $tx->wallet->name ?? '-');
            $sheet->setCellValue('E' . $row, $tx->note ?? '-');
            
            if ($tx->type === 'masuk') $totalMasuk += $tx->amount;
            else $totalKeluar += $tx->amount;
            
            $row++;
        }
        
        $row++;
        $sheet->setCellValue('B' . $row, 'Total Masuk:');
        $sheet->setCellValue('C' . $row, $totalMasuk);
        $row++;
        $sheet->setCellValue('B' . $row, 'Total Keluar:');
        $sheet->setCellValue('C' . $row, $totalKeluar);

        $writer = new Xlsx($spreadsheet);
        $tempFile = tempnam(sys_get_temp_dir(), 'excel') . '.xlsx';
        $writer->save($tempFile);
        
        return response()->download($tempFile, 'rekap.xlsx')->deleteFileAfterSend(true);
    }

    public function word(Request $request)
    {
        $transactions = $request->user()->transactions()->with('wallet')->whereDate('created_at', today())->get();
        
        $phpWord = new PhpWord();
        $section = $phpWord->addSection();
        
        $section->addText('Rekap Transaksi Hari Ini', ['name' => 'Arial', 'size' => 16, 'bold' => true]);
        $section->addTextBreak(1);
        
        $tableStyle = ['borderSize' => 6, 'borderColor' => '000000', 'cellMargin' => 50];
        $phpWord->addTableStyle('Rekap Table', $tableStyle);
        $table = $section->addTable('Rekap Table');
        
        $table->addRow();
        $table->addCell(1500)->addText('Tipe', ['bold' => true]);
        $table->addCell(2000)->addText('Kategori', ['bold' => true]);
        $table->addCell(2000)->addText('Nominal', ['bold' => true]);
        $table->addCell(2000)->addText('Dompet', ['bold' => true]);
        $table->addCell(3000)->addText('Catatan', ['bold' => true]);
        
        $totalMasuk = 0;
        $totalKeluar = 0;
        foreach ($transactions as $tx) {
            $table->addRow();
            $table->addCell(1500)->addText(ucfirst($tx->type));
            $table->addCell(2000)->addText($tx->category);
            $table->addCell(2000)->addText('Rp ' . number_format($tx->amount, 0, ',', '.'));
            $table->addCell(2000)->addText($tx->wallet->name ?? '-');
            $table->addCell(3000)->addText($tx->note ?? '-');
            
            if ($tx->type === 'masuk') $totalMasuk += $tx->amount;
            else $totalKeluar += $tx->amount;
        }
        
        $section->addTextBreak(1);
        $section->addText('Total Pemasukan: Rp ' . number_format($totalMasuk, 0, ',', '.'), ['bold' => true]);
        $section->addText('Total Pengeluaran: Rp ' . number_format($totalKeluar, 0, ',', '.'), ['bold' => true]);
        
        $objWriter = IOFactory::createWriter($phpWord, 'Word2007');
        $tempFile = tempnam(sys_get_temp_dir(), 'word') . '.docx';
        $objWriter->save($tempFile);
        
        return response()->download($tempFile, 'rekap.docx')->deleteFileAfterSend(true);
    }
}
