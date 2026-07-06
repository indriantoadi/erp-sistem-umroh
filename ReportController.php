<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Auth;

class ReportController extends Controller
{
    public function profitability(Request $request)
    {
        $user = Auth::user();
        $isAdminPusat = ($user->branch_id == 1 || is_null($user->branch_id));

        // Ambil input tanggal dari request, default ke awal & akhir bulan ini
        $tglAwal = $request->input('tgl_awal', date('Y-m-01'));
        $tglAkhir = $request->input('tgl_akhir', date('Y-m-t'));

        $query = DB::table('bld_packages')
            ->leftJoin('bld_bookings', function($join) {
                $join->on('bld_packages.id', '=', 'bld_bookings.package_id')
                     ->where('bld_bookings.status', '!=', 'cancelled');
            });

        // 1. Filter Akses Cabang
        if (!$isAdminPusat) {
            $query->where('bld_bookings.branch_id', $user->branch_id);
        }

        // 2. Filter Periode Tanggal Keberangkatan
        $query->whereBetween('bld_packages.departure_date', [$tglAwal, $tglAkhir]);

        $reports = $query->select(
                'bld_packages.id',
                'bld_packages.name as package_name',
                'bld_packages.departure_date',
                DB::raw('COUNT(bld_bookings.id) as total_jamaah'),
                DB::raw('COALESCE(SUM(bld_bookings.selling_price), 0) as total_revenue'),
                DB::raw('COALESCE(SUM(bld_bookings.price_hpp_snapshot), 0) as total_hpp'),
                DB::raw('COALESCE(SUM(bld_bookings.total_paid), 0) as total_cash_in')
            )
            ->groupBy('bld_packages.id', 'bld_packages.name', 'bld_packages.departure_date')
            ->orderBy('bld_packages.departure_date', 'asc')
            ->get();

        return view('finance.reports.profitability', [
            'reports'      => $reports,
            'isAdminPusat' => $isAdminPusat,
            'tglAwal'      => $tglAwal,
            'tglAkhir'     => $tglAkhir
        ]);
    }

    public function exportExcel(Request $request)
    {
        $user = Auth::user();
        $isAdminPusat = ($user->branch_id == 1 || is_null($user->branch_id));

        $tglAwal = $request->input('tgl_awal', date('Y-m-01'));
        $tglAkhir = $request->input('tgl_akhir', date('Y-m-t'));

        $query = DB::table('bld_packages')
            ->leftJoin('bld_bookings', function($join) {
                $join->on('bld_packages.id', '=', 'bld_bookings.package_id')
                     ->where('bld_bookings.status', '!=', 'cancelled');
            });

        if (!$isAdminPusat) {
            $query->where('bld_bookings.branch_id', $user->branch_id);
        }

        $query->whereBetween('bld_packages.departure_date', [$tglAwal, $tglAkhir]);

        $reports = $query->select(
                'bld_packages.name as package_name',
                'bld_packages.departure_date',
                DB::raw('COUNT(bld_bookings.id) as total_jamaah'),
                DB::raw('COALESCE(SUM(bld_bookings.selling_price), 0) as total_revenue'),
                DB::raw('COALESCE(SUM(bld_bookings.price_hpp_snapshot), 0) as total_hpp'),
                DB::raw('COALESCE(SUM(bld_bookings.total_paid), 0) as total_cash_in')
            )
            ->groupBy('bld_packages.id', 'bld_packages.name', 'bld_packages.departure_date')
            ->orderBy('bld_packages.departure_date', 'asc')
            ->get();

        $fileName = "Laporan_Profit_Paket_" . date('Ymd_His') . ".xls";

        header("Content-type: application/vnd.ms-excel");
        header("Content-Disposition: attachment; filename=$fileName");
        echo "<html><head><meta charset='UTF-8'></head><body>";

        // JEDER! Gambar tabel Excel, sesuaikan kolom dengan kasta User
        echo "<table border='1' cellpadding='5'>
                <tr style='background-color:#e2e8f0; font-weight:bold; text-align:center;'>
                    <th>Nama Paket</th>
                    <th>Keberangkatan</th>
                    <th>Jemaah</th>";

        if ($isAdminPusat) {
            echo "<th>Total Modal (HPP)</th>";
        }

        echo "<th>Total Omzet (Jual)</th>";

        if ($isAdminPusat) {
            echo "<th>Estimasi Profit</th>";
        }

        echo "<th>Uang Masuk (Cash In)</th>
              </tr>";

        foreach ($reports as $r) {
            $profit = $r->total_revenue - $r->total_hpp;

            echo "<tr>
                    <td>" . strtoupper($r->package_name) . "</td>
                    <td style='text-align:center;'>" . $r->departure_date . "</td>
                    <td style='text-align:center;'>" . $r->total_jamaah . "</td>";

            if ($isAdminPusat) {
                echo "<td style='text-align:right;'>" . $r->total_hpp . "</td>";
            }

            echo "<td style='text-align:right;'>" . $r->total_revenue . "</td>";

            if ($isAdminPusat) {
                echo "<td style='text-align:right;'>" . $profit . "</td>";
            }

            echo "<td style='text-align:right;'>" . $r->total_cash_in . "</td>
                  </tr>";
        }
        echo "</table></body></html>";
        exit;
    }
    public function outstanding(Request $request)
    {
        $user = \Illuminate\Support\Facades\Auth::user();
        $isAdminPusat = ($user->branch_id == 1 || is_null($user->branch_id));

        // Tangkap input filter
        $tglAwal = $request->input('tgl_awal');
        $tglAkhir = $request->input('tgl_akhir');

        $query = DB::table('bld_bookings')
            ->join('bld_packages', 'bld_bookings.package_id', '=', 'bld_packages.id')
            ->join('bld_jamaahs', 'bld_bookings.jamaah_id', '=', 'bld_jamaahs.id')
            ->where('bld_bookings.status', '!=', 'cancelled')
            ->whereRaw('bld_bookings.total_paid < bld_bookings.selling_price');

        if (!$isAdminPusat) {
            $query->where('bld_bookings.branch_id', $user->branch_id);
        }

        // JEDER! Filter berdasarkan Periode Keberangkatan
        if ($tglAwal && $tglAkhir) {
            $query->whereBetween('bld_packages.departure_date', [$tglAwal, $tglAkhir]);
        }

        $outstandings = $query->select(
                'bld_bookings.id',
                'bld_bookings.booking_code',
                'bld_bookings.selling_price',
                'bld_bookings.total_paid',
                'bld_packages.name as package_name',
                'bld_packages.departure_date',
                'bld_jamaahs.name as customer_name',
                'bld_jamaahs.phone',
                DB::raw('(bld_bookings.selling_price - bld_bookings.total_paid) as sisa_tagihan')
            )
            ->orderBy('bld_packages.departure_date', 'asc')
            ->get();

        // JEDER! Logic kalau tombol "Export Excel" dipencet (Versi Tabel Rapih)
    if ($request->has('export') && $request->export == 'excel') {
        $fileName = "Laporan_Piutang_Jamaah_" . date('Y-m-d') . ".xls";

        $headers = [
            "Content-type"        => "application/vnd.ms-excel",
            "Content-Disposition" => "attachment; filename=$fileName",
            "Pragma"              => "no-cache",
            "Cache-Control"       => "must-revalidate, post-check=0, pre-check=0",
            "Expires"             => "0"
        ];

        $callback = function() use($outstandings) {
            // Mulai bikin kerangka Tabel HTML yang akan dibaca sebagai Excel
            echo '<table border="1" style="border-collapse: collapse; width: 100%; font-family: Arial, sans-serif;">';

            // Judul Laporan
            echo '<tr>';
            echo '<th colspan="9" style="font-size: 16px; font-weight: bold; text-align: center; padding: 15px;">LAPORAN OUTSTANDING PAYMENT (PIUTANG JEMAAH)</th>';
            echo '</tr>';

            // Header Tabel (Gue kasih warna background abu-abu biar cakep)
            echo '<tr style="background-color: #d1d5db; font-weight: bold; text-align: center;">';
            echo '<th style="padding: 10px;">No</th>';
            echo '<th style="padding: 10px;">Nama Jamaah</th>';
            echo '<th style="padding: 10px;">Kode Booking</th>';
            echo '<th style="padding: 10px;">Paket</th>';
            echo '<th style="padding: 10px;">Tgl Berangkat</th>';
            echo '<th style="padding: 10px;">No WA</th>';
            echo '<th style="padding: 10px;">Total Tagihan</th>';
            echo '<th style="padding: 10px;">Sudah Dibayar</th>';
            echo '<th style="padding: 10px;">Sisa Kurang</th>';
            echo '</tr>';

            $totalPiutang = 0;
            $no = 1;

            foreach ($outstandings as $row) {
                $totalPiutang += $row->sisa_tagihan;
                echo '<tr>';
                echo '<td style="text-align: center; padding: 5px;">' . $no++ . '</td>';
                echo '<td style="padding: 5px;">' . $row->customer_name . '</td>';
                echo '<td style="text-align: center; padding: 5px; font-weight: bold;">' . $row->booking_code . '</td>';
                echo '<td style="padding: 5px;">' . $row->package_name . '</td>';
                echo '<td style="text-align: center; padding: 5px;">' . date('d M Y', strtotime($row->departure_date)) . '</td>';
                // Tambah tanda petik satu (') di depan No WA biar angka 0 di depannya ga hilang di Excel
                echo '<td style="padding: 5px;">\'' . $row->phone . '</td>';
                echo '<td style="text-align: right; padding: 5px;">' . $row->selling_price . '</td>';
                echo '<td style="text-align: right; padding: 5px;">' . $row->total_paid . '</td>';
                // Kolom Piutang gue kasih warna teks merah biar langsung keliatan
                echo '<td style="text-align: right; color: red; font-weight: bold; padding: 5px;">' . $row->sisa_tagihan . '</td>';
                echo '</tr>';
            }

            // Footer / Baris Total (Warna background merah muda)
            echo '<tr style="background-color: #fee2e2; font-weight: bold;">';
            echo '<td colspan="8" style="text-align: right; padding: 10px;">TOTAL UANG MENGENDAP (PIUTANG) :</td>';
            echo '<td style="text-align: right; color: red; padding: 10px;">' . $totalPiutang . '</td>';
            echo '</tr>';

            echo '</table>';
        };

        return response()->stream($callback, 200, $headers);
    }

        return view('finance.reports.outstanding', compact('outstandings', 'isAdminPusat', 'tglAwal', 'tglAkhir'));
    }
    public function branchPerformance(Request $request)
    {
        $user = \Illuminate\Support\Facades\Auth::user();
        $isAdminPusat = ($user->branch_id == 1 || is_null($user->branch_id));

        $tglAwal = $request->input('tgl_awal');
        $tglAkhir = $request->input('tgl_akhir');

        $query = DB::table('bld_bookings')
            // Sesuaikan nama tabel cabang lu ya (misal: branches atau bld_branches)
            ->leftJoin('bld_branches', 'bld_bookings.branch_id', '=', 'bld_branches.id')
            ->join('bld_packages', 'bld_bookings.package_id', '=', 'bld_packages.id')
            ->where('bld_bookings.status', '!=', 'cancelled');

        // Proteksi: Kalau Cabang yang login, dia cuma bisa liat rapor dia sendiri
        if (!$isAdminPusat) {
            $query->where('bld_bookings.branch_id', $user->branch_id);
        }

        // Filter Periode Berangkat
        if ($tglAwal && $tglAkhir) {
            $query->whereBetween('bld_packages.departure_date', [$tglAwal, $tglAkhir]);
        }

        $performances = $query->select(
                DB::raw('COALESCE(bld_branches.name, "Pusat / Utama") as branch_name'),
                DB::raw('COUNT(bld_bookings.id) as total_jamaah'),
                DB::raw('SUM(bld_bookings.selling_price) as total_omzet'),
                DB::raw('SUM(bld_bookings.price_hpp_snapshot) as total_hpp'),
                DB::raw('SUM(bld_bookings.total_paid) as total_setoran'),
                // Komisi / Margin Kotor Cabang
                DB::raw('SUM(bld_bookings.selling_price - bld_bookings.price_hpp_snapshot) as total_margin')
            )
            ->groupBy('bld_bookings.branch_id', 'bld_branches.name')
            ->orderBy('total_jamaah', 'desc') // Urutin dari cabang yang paling laku
            ->get();

        // JEDER! Fitur Export Excel Tabel Rapih
        if ($request->has('export') && $request->export == 'excel') {
            $fileName = "Laporan_Performa_Cabang_" . date('Y-m-d') . ".xls";

            $headers = [
                "Content-type"        => "application/vnd.ms-excel",
                "Content-Disposition" => "attachment; filename=$fileName",
                "Pragma"              => "no-cache",
                "Cache-Control"       => "must-revalidate, post-check=0, pre-check=0",
                "Expires"             => "0"
            ];

            $callback = function() use($performances, $tglAwal, $tglAkhir) {
                echo '<table border="1" style="border-collapse: collapse; width: 100%; font-family: Arial, sans-serif;">';

                echo '<tr>';
                echo '<th colspan="7" style="font-size: 16px; font-weight: bold; text-align: center; padding: 15px;">LAPORAN PERFORMA & KOMISI CABANG</th>';
                echo '</tr>';

                if ($tglAwal && $tglAkhir) {
                    echo '<tr><th colspan="7" style="text-align: center; padding: 5px;">Periode Berangkat: ' . $tglAwal . ' s/d ' . $tglAkhir . '</th></tr>';
                }

                echo '<tr style="background-color: #d1d5db; font-weight: bold; text-align: center;">';
                echo '<th style="padding: 10px;">No</th>';
                echo '<th style="padding: 10px;">Nama Cabang</th>';
                echo '<th style="padding: 10px;">Total Jamaah (Pax)</th>';
                echo '<th style="padding: 10px;">Omzet Penjualan</th>';
                echo '<th style="padding: 10px;">Beban HPP (Modal Pusat)</th>';
                echo '<th style="padding: 10px;">Uang Masuk (Setoran)</th>';
                echo '<th style="padding: 10px; background-color: #fef08a;">Margin / Estimasi Komisi</th>';
                echo '</tr>';

                $no = 1;
                $grandJamaah = 0; $grandOmzet = 0; $grandMargin = 0;

                foreach ($performances as $row) {
                    $grandJamaah += $row->total_jamaah;
                    $grandOmzet += $row->total_omzet;
                    $grandMargin += $row->total_margin;

                    echo '<tr>';
                    echo '<td style="text-align: center; padding: 5px;">' . $no++ . '</td>';
                    echo '<td style="padding: 5px; font-weight: bold;">' . $row->branch_name . '</td>';
                    echo '<td style="text-align: center; padding: 5px;">' . $row->total_jamaah . '</td>';
                    echo '<td style="text-align: right; padding: 5px;">' . $row->total_omzet . '</td>';
                    echo '<td style="text-align: right; color: red; padding: 5px;">' . $row->total_hpp . '</td>';
                    echo '<td style="text-align: right; color: green; padding: 5px;">' . $row->total_setoran . '</td>';
                    echo '<td style="text-align: right; font-weight: bold; background-color: #fef9c3; padding: 5px;">' . $row->total_margin . '</td>';
                    echo '</tr>';
                }

                // Footer Total
                echo '<tr style="background-color: #1e293b; color: white; font-weight: bold;">';
                echo '<td colspan="2" style="text-align: right; padding: 10px;">GRAND TOTAL :</td>';
                echo '<td style="text-align: center; padding: 10px;">' . $grandJamaah . ' Pax</td>';
                echo '<td style="text-align: right; padding: 10px;">' . $grandOmzet . '</td>';
                echo '<td colspan="2"></td>';
                echo '<td style="text-align: right; color: #fde047; padding: 10px;">' . $grandMargin . '</td>';
                echo '</tr>';

                echo '</table>';
            };

            return response()->stream($callback, 200, $headers);
        }

        return view('finance.reports.branch_performance', compact('performances', 'isAdminPusat', 'tglAwal', 'tglAkhir'));
    }
}
