<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;
use Intervention\Image\Laravel\Facades\Image;

class BookingController extends Controller
{
   public function index()
{
    $user = Auth::user();
    // Proteksi kasta: Cek apakah user pusat atau cabang
    $isPusat = in_array($user->role, ['super_admin', 'finance', 'admin']) && is_null($user->branch_id);

    $query = DB::table('bld_packages')
        ->join('bld_categories', 'bld_packages.category_id', '=', 'bld_categories.id')
        ->leftJoin('bld_package_hotels', 'bld_packages.id', '=', 'bld_package_hotels.package_id')
        ->leftJoin('bld_hotels', 'bld_package_hotels.hotel_id', '=', 'bld_hotels.id')
        ->leftJoin('bld_package_flights', 'bld_packages.id', '=', 'bld_package_flights.package_id')
        ->join('bld_package_rates', 'bld_packages.id', '=', 'bld_package_rates.package_id');

    // Jika Cabang: Join ke tabel Mark-up Harga milik Cabang tersebut
    if (!$isPusat) {
        $query->leftJoin('bld_package_branch_rates', function($join) use ($user) {
            $join->on('bld_packages.id', '=', 'bld_package_branch_rates.package_id')
                 ->where('bld_package_branch_rates.branch_id', '=', $user->branch_id);
        });
    }

    $packages = $query->select(
            'bld_packages.id',
            'bld_packages.name',
            'bld_packages.departure_date',
            'bld_packages.quota',
            'bld_packages.days',
            'bld_categories.name as category_name',

            // Logic COALESCE: Ambil harga cabang, kalau kosong ambil harga pusat
            DB::raw($isPusat ? 'bld_package_rates.price_quad' : 'COALESCE(bld_package_branch_rates.price_quad, bld_package_rates.price_quad) as price_quad'),
            DB::raw($isPusat ? 'bld_package_rates.price_triple' : 'COALESCE(bld_package_branch_rates.price_triple, bld_package_rates.price_triple) as price_triple'),
            DB::raw($isPusat ? 'bld_package_rates.price_double' : 'COALESCE(bld_package_branch_rates.price_double, bld_package_rates.price_double) as price_double'),

            DB::raw("MAX(CASE WHEN bld_package_hotels.city_name = 'Makkah' THEN bld_hotels.name END) as nama_hotel_makkah"),
            DB::raw("MAX(CASE WHEN bld_package_hotels.city_name = 'Madinah' THEN bld_hotels.name END) as nama_hotel_madinah"),
            DB::raw("GROUP_CONCAT(DISTINCT bld_package_flights.airline SEPARATOR ', ') as airlines")
        )
        ->where('bld_packages.departure_date', '>=', now())
        ->groupBy(
            'bld_packages.id',
            'bld_packages.name',
            'bld_packages.departure_date',
            'bld_packages.quota',
            'bld_packages.days',
            'bld_categories.name',
            'bld_package_rates.price_quad',
            'bld_package_rates.price_triple',
            'bld_package_rates.price_double'
        );

    // Filter GroupBy tambahan untuk Cabang (Sudah difix typonya)
    if (!$isPusat) {
        $query->groupBy(
            'bld_package_branch_rates.price_quad',
            'bld_package_branch_rates.price_triple',
            'bld_package_branch_rates.price_double'
        );
    }

    $packages = $query->orderBy('bld_packages.departure_date', 'asc')->get();

    return view('bookings.index', compact('packages'));
}

   public function create(Request $request)
{
    $user = Auth::user();
    // Cek kasta: Apakah ini user pusat atau cabang
    $isPusat = in_array($user->role, ['super_admin', 'finance', 'admin']) && is_null($user->branch_id);

    $query = DB::table('bld_packages')
        ->join('bld_package_rates', 'bld_packages.id', '=', 'bld_package_rates.package_id');

    // JIKA CABANG: Join ke tabel Mark-up mereka
    if (!$isPusat) {
        $query->leftJoin('bld_package_branch_rates', function($join) use ($user) {
            $join->on('bld_packages.id', '=', 'bld_package_branch_rates.package_id')
                 ->where('bld_package_branch_rates.branch_id', '=', $user->branch_id);
        });
    }

    $package = $query->select(
            'bld_packages.*',
            // Logic COALESCE: Jika ada harga cabang pakai itu, jika NULL pakai harga pusat
            DB::raw($isPusat ? 'bld_package_rates.price_quad' : 'COALESCE(bld_package_branch_rates.price_quad, bld_package_rates.price_quad) as price_quad'),
            DB::raw($isPusat ? 'bld_package_rates.price_triple' : 'COALESCE(bld_package_branch_rates.price_triple, bld_package_rates.price_triple) as price_triple'),
            DB::raw($isPusat ? 'bld_package_rates.price_double' : 'COALESCE(bld_package_branch_rates.price_double, bld_package_rates.price_double) as price_double'),
            DB::raw($isPusat ? 'bld_package_rates.price_quint' : 'COALESCE(bld_package_branch_rates.price_quint, bld_package_rates.price_quint) as price_quint')
        )
        ->where('bld_packages.id', $request->package_id)
        ->first();

    if (!$package) return redirect()->route('booking.index')->with('error', 'Data Harga Paket belum ada!');

    // Kondisi untuk dropdown cabang di Form Booking
    $branches = DB::table('bld_branches');
    if (!$isPusat) {
        // Kalau cabang yang buka, dropdown cabangnya cuma muncul cabang dia sendiri (biar gak salah pilih)
        $branches->where('id', $user->branch_id);
    }
    $branches = $branches->orderBy('name', 'asc')->get();

    return view('bookings.form', compact('package', 'branches'));
}
    public function store(Request $request)
{
    // 1. Validasi (branch_id dihapus karena otomatis dari sistem)
    $request->validate([
        'nik'          => 'required|numeric|digits:16',
        'full_name'    => 'required|string',
        'package_id'   => 'required',
        'room_type'    => 'required',
        'fee_amount'   => 'nullable|numeric|min:0',
        'pay_image'    => 'nullable|image|mimes:jpg,jpeg,png|max:2048',
    ]);

    try {
        DB::beginTransaction();

        // 2. Ambil Harga Paket dari bld_package_rates (Bukan bld_packages)
        $rates = DB::table('bld_package_rates')->where('package_id', $request->package_id)->first();
        if (!$rates) {
            throw new \Exception("Harga jual paket belum disetting oleh pusat!");
        }
        $priceField = 'price_' . strtolower($request->room_type);
        $sellingPrice = $rates->$priceField ?? 0;

        // 3. Cek Master Inventory Kamar (Otomatis potong stok)
        $roomTypeCapital = ucfirst(strtolower($request->room_type));
        $inventory = DB::table('bld_hotel_rooms')
            ->where('package_id', $request->package_id)
            ->where('room_type', $roomTypeCapital)
            ->first();

        if ($inventory) {
            if ($inventory->used_capacity >= $inventory->total_capacity) {
                throw new \Exception("Kamar tipe {$roomTypeCapital} sudah FULL BOOKED!");
            }
            DB::table('bld_hotel_rooms')->where('id', $inventory->id)->increment('used_capacity');
        }

        // 4. Handle Data Jamaah (Upsert)
        DB::table('bld_jamaahs')->updateOrInsert(
            ['nik' => $request->nik],
            [
                'name'       => $request->full_name,
                'pob'        => $request->pob,
                'dob'        => $request->dob,
                'phone'      => $request->phone,
                'gender'     => $request->gender,
                'address'    => $request->address,
                'updated_at' => now()
            ]
        );

        $jamaah = DB::table('bld_jamaahs')->where('nik', $request->nik)->first();

        // 5. Setup Status Pembayaran (Disamakan dengan Enum DB lu: pending, partial, paid)
        $totalPaid = $request->with_payment ? ($request->pay_amount ?? 0) : 0;
        $status = ($totalPaid >= $sellingPrice) ? 'paid' : ($totalPaid > 0 ? 'partial' : 'pending');
        $feeStatus = ($status == 'paid' && $request->fee_amount > 0) ? 'unpaid' : ($request->fee_amount > 0 ? 'unpaid' : 'no_fee');

        // Ambil Data Admin yang lagi login (Buat Cabang & Created By)
        $userLogin = \Illuminate\Support\Facades\Auth::user();
        $cabang_id = $userLogin->branch_id ?? 1;
        $hppData = DB::table('bld_package_hpp')
            ->where('package_id', $request->package_id)
            ->first();

        // Ambil kolom HPP sesuai tipe kamar, default ke 0 kalau gak ada
        $hppField = 'hpp_' . strtolower($request->room_type);
        $hppValue = $hppData->$hppField ?? 0;
        $discount = $rates->discount ?? 0;
        // 6. Simpan ke bld_bookings (Sesuai Struktur Database Lu)
        $bookingId = DB::table('bld_bookings')->insertGetId([
            'booking_code'       => 'BK-' . date('Ym') . strtoupper(\Illuminate\Support\Str::random(4)),
            'package_id'         => $request->package_id,
            'branch_id'          => $cabang_id,
            'jamaah_id'          => $jamaah->id,
            'room_type'          => strtolower($request->room_type), // Wajib huruf kecil (quad, dll)

            // JEDER! Mencegah Error 1364 (Data yang wajib diisi di DB)
            'base_price'         => $sellingPrice, // Harga normal sebelum diskon

            // JEDER! Diskon otomatis
            'discount'           => $discount,

            // JEDER! HPP otomatis
            'price_hpp_snapshot' => $hppValue,

            // Harga setelah dikurangi diskon
            'selling_price'      => ($sellingPrice - $discount),
            'total_paid'         => $totalPaid,
            'status'             => $status,
            'total_fee_amount'   => $request->fee_amount ?? 0,
            'fee_status'         => $feeStatus,
            'created_at'         => now(),
            'updated_at'         => now(),
        ]);

        // 7. Handle File Bukti Bayar & Entry ke bld_payments
        if ($request->with_payment && $request->pay_amount > 0) {
            $fileName = null;
            if ($request->hasFile('pay_image')) {
                $fileName = 'DP_' . time() . '_' . \Illuminate\Support\Str::random(5) . '.webp';
                $uploadPath = public_path('storage/payments');
                if (!file_exists($uploadPath)) { mkdir($uploadPath, 0777, true); }

                // Pake Intervention Image versi terbaru
                \Intervention\Image\Laravel\Facades\Image::read($request->file('pay_image'))
                    ->scale(width: 800)
                    ->toWebp(60)
                    ->save($uploadPath . '/' . $fileName);
            }

            DB::table('bld_payments')->insert([
                'booking_id'       => $bookingId,
                'receipt_number'   => 'RCP-' . date('Ymd') . '-' . strtoupper(\Illuminate\Support\Str::random(5)),
                'amount'           => $request->pay_amount,
                'method'           => $request->pay_method ?? 'transfer',
                'status'           => 'PAID',
                'payment_date'     => now(),
                'proof_of_payment' => $fileName,
                'created_by'       => $userLogin->id ?? 1,
                'created_at'       => now(),
            ]);
        }

        // 8. Kurangi Kuota Global Paket
        DB::table('bld_packages')->where('id', $request->package_id)->decrement('quota');

        DB::commit();
        return redirect()->route('booking.list')->with('success', 'Booking berhasil disimpan & Stok Kamar Terpotong!');

    } catch (\Exception $e) {
        DB::rollBack();
        return back()->with('error', 'Gagal Simpan: ' . $e->getMessage());
    }
}

    public function list()
    {
        $user = Auth::user();
        // Tentukan role pusat (Super Admin, Finance, atau Admin tanpa branch_id)
        $isPusat = in_array($user->role, ['super_admin', 'finance', 'admin']) && is_null($user->branch_id);

        $query = DB::table('bld_bookings')
            ->join('bld_jamaahs', 'bld_bookings.jamaah_id', '=', 'bld_jamaahs.id')
            ->join('bld_packages', 'bld_bookings.package_id', '=', 'bld_packages.id')
            ->leftJoin('bld_branches', 'bld_bookings.branch_id', '=', 'bld_branches.id')
            ->select(
                'bld_bookings.*',
                'bld_jamaahs.name as jamaah_name',
                'bld_packages.name as package_name',
                'bld_branches.name as branch_name'
            );

        // --- LOGIC FILTER ---
        // Jika bukan pusat, maka hanya boleh lihat data miliknya sendiri
        if (!$isPusat) {
            $query->where('bld_bookings.branch_id', $user->branch_id);
        }

        $bookings = $query->orderBy('bld_bookings.created_at', 'desc')->get();

        return view('bookings.list', compact('bookings'));
    }

    public function show($id)
    {
        $booking = DB::table('bld_bookings')
            ->join('bld_jamaahs', 'bld_bookings.jamaah_id', '=', 'bld_jamaahs.id')
            ->join('bld_packages', 'bld_bookings.package_id', '=', 'bld_packages.id')
            ->leftJoin('bld_branches', 'bld_bookings.branch_id', '=', 'bld_branches.id')
            ->select('bld_bookings.*', 'bld_jamaahs.name as jamaah_name', 'bld_jamaahs.address', 'bld_packages.departure_date', 'bld_jamaahs.phone', 'bld_jamaahs.nik', 'bld_packages.name as package_name', 'bld_branches.name')
            ->where('bld_bookings.id', $id)
            ->first();

        if (!$booking) return redirect()->route('booking.list')->with('error', 'Data tidak ditemukan!');

        $items = DB::table('bld_items')->where('stock', '>', 0)->get();
        $payments = DB::table('bld_payments')->where('booking_id', $id)->get();

        return view('bookings.show', compact('booking', 'items', 'payments'));
    }

    public function confirmPayment($id)
    {
        try {
            DB::beginTransaction();

            $booking = DB::table('bld_bookings')->where('id', $id)->first();
            if (!$booking) return back()->with('error', 'Booking tidak ada!');

            $updateData = [
                'status' => 'paid',
                'updated_at' => now()
            ];

            // Aktifkan antrian fee jika jamaah sudah lunas
            if($booking->total_fee_amount > 0) {
                $updateData['fee_status'] = 'unpaid';
            }

            DB::table('bld_bookings')->where('id', $id)->update($updateData);

            DB::commit();
            return back()->with('success', 'Status Lunas! Komisi Cabang kini aktif di antrian bayar.');
        } catch (\Exception $e) {
            DB::rollBack();
            return back()->with('error', $e->getMessage());
        }
    }

    public function destroy($id)
    {
        try {
            DB::beginTransaction();
            $booking = DB::table('bld_bookings')->where('id', $id)->first();
            if (!$booking) return back()->with('error', 'Data tidak ditemukan!');

            // Balikin Stok & Kuota
            $logistics = DB::table('bld_booking_logistics')->where('booking_id', $id)->get();
            foreach ($logistics as $log) {
                DB::table('bld_items')->where('id', $log->item_id)->increment('stock', $log->qty);
            }
            DB::table('bld_packages')->where('id', $booking->package_id)->increment('quota');

            // Hapus Relasi
            DB::table('bld_payments')->where('booking_id', $id)->delete();
            DB::table('bld_bookings')->where('id', $id)->delete();

            DB::commit();
            return redirect()->route('booking.list')->with('success', 'Booking berhasil dihapus!');
        } catch (\Exception $e) {
            DB::rollBack();
            return back()->with('error', $e->getMessage());
        }
    }
    public function pindahKamar(Request $request, $id)
    {
        // 1. Validasi input
        $request->validate([
            'new_room_type' => 'required|in:quad,triple,double,quint'
        ]);

        // 2. Bungkus pakai DB Transaction
        DB::transaction(function () use ($request, $id) {

            // Ambil data dari tabel bld_bookings
            $booking = DB::table('bld_bookings')->where('id', $id)->first();

            if (!$booking) {
                throw new \Exception('Data booking tidak ditemukan!');
            }

            $newRoom = $request->new_room_type;

            // Cegah kalau kamarnya sama
            if ($booking->room_type == $newRoom) {
                throw new \Exception('Kamar yang dipilih sama dengan kamar saat ini.');
            }

            // 3. LOGIC HARGA CABANG VS PUSAT
            $priceColumn = 'price_' . $newRoom; // misal: price_double
            $newBasePrice = 0;

            // Cek dulu apakah booking ini lewat cabang dan cabangnya punya harga khusus
            if ($booking->branch_id) {
                $branchRate = DB::table('bld_package_branch_rates')
                                ->where('package_id', $booking->package_id)
                                ->where('branch_id', $booking->branch_id)
                                ->first();

                if ($branchRate && $branchRate->$priceColumn > 0) {
                    $newBasePrice = $branchRate->$priceColumn;
                }
            }

            // Kalau ternyata gak ada harga cabang (atau booking dari pusat), ambil harga Master
            if ($newBasePrice == 0) {
                $masterRate = DB::table('bld_package_rates')
                                ->where('package_id', $booking->package_id)
                                ->first();

                if (!$masterRate || $masterRate->$priceColumn == 0) {
                    throw new \Exception('Harga paket untuk tipe kamar ini belum disetting!');
                }
                $newBasePrice = $masterRate->$priceColumn;
            }

            // 4. KALKULASI HARGA & STATUS BARU
            // Harga jual baru = Harga dasar baru dikurangi diskon yang udah dikasih sebelumnya
            $newSellingPrice = $newBasePrice - $booking->discount;

            // Cek status berdasarkan total yang sudah dibayar (total_paid)
            $newStatus = 'pending';
            if ($booking->total_paid >= $newSellingPrice) {
                $newStatus = 'paid'; // Duit masuk udah nutupin harga baru
            } elseif ($booking->total_paid > 0) {
                $newStatus = 'partial'; // Masih ada kurang
            }

            // 5. UPDATE KE DATABASE
            DB::table('bld_bookings')->where('id', $id)->update([
                'room_type'     => $newRoom,
                'base_price'    => $newBasePrice,
                'selling_price' => $newSellingPrice,
                'status'        => $newStatus,
                'updated_at'    => now()
            ]);
        });

        return redirect()->back()->with('success', 'JEDER! Jemaah berhasil pindah kamar, harga dan status otomatis disesuaikan!');
    }
    public function prosesRefund(Request $request, $booking_id)
{
    DB::transaction(function () use ($request, $booking_id) {
        $booking = DB::table('bld_bookings')->where('id', $booking_id)->first();

        // Nominal refund (angka positif yang bakal jadi minus di database)
        $refundAmount = $request->amount;

        // 1. Insert ke tabel bld_payments sebagai Uang Keluar (Minus)
        DB::table('bld_payments')->insert([
            'booking_id'      => $booking_id,
            'receipt_number'  => 'REF-' . date('Ymd') . '-' . strtoupper(uniqid()),
            'amount'          => -$refundAmount, // MINUS DISINI
            'method'          => 'transfer',
            'status'          => 'PAID', // Statusnya PAID karena refund sudah sukses dilakukan
            'payment_date'    => now(),
            'created_at'      => now(),
            'updated_at'      => now()
        ]);

        // 2. Update status booking kalau perlu
        // Kalau setelah refund sisa tagihannya jadi 0, status jadi 'paid'
        // Ini opsional, tergantung logic sistem lu
    });

    return redirect()->back()->with('success', 'Refund berhasil dicatat!');
}
public function kelebihanDana()
{
    $overpayments = DB::table('bld_bookings')
        ->leftJoin('bld_jamaahs', 'bld_bookings.jamaah_id', '=', 'bld_jamaahs.id')
        ->select('bld_bookings.*', 'bld_jamaahs.name as jamaah_name')
        ->whereRaw('bld_bookings.total_paid > bld_bookings.selling_price')
        ->where('bld_bookings.selling_price', '>', 0)
        ->get();

    return view('finance.kelebihan_dana', compact('overpayments'));
}
public function potongHpp(Request $request)
{
    // 1. Proteksi akses khusus Admin Pusat
    $user = \Illuminate\Support\Facades\Auth::user();
    if ($user->branch_id != 1 && !is_null($user->branch_id)) {
        return back()->with('error', 'Akses Ditolak! Fitur ini khusus Admin Pusat.');
    }

    // 2. Validasi Input
    $request->validate([
        'booking_id'        => 'required',
        'amount'            => 'required|numeric|min:1',
        'request_branch_id' => 'required'
    ]);

    try {
        \Illuminate\Support\Facades\DB::beginTransaction();

        $booking = \Illuminate\Support\Facades\DB::table('bld_bookings')->where('id', $request->booking_id)->first();
        if (!$booking) {
            throw new \Exception('Data booking tidak ditemukan!');
        }

        // 3. Validasi: Jangan sampe modal (HPP) jadi minus
        if ($booking->price_hpp_snapshot < $request->amount) {
            throw new \Exception('Gagal! Jumlah potongan lebih besar dari HPP saat ini.');
        }

        $amount = (float) $request->amount;

        // 4. JEDER! CUMA KOLOM HPP YANG DITURUNKAN. Selling Price aman nggak disentuh!
        \Illuminate\Support\Facades\DB::table('bld_bookings')->where('id', $request->booking_id)->update([
            'price_hpp_snapshot' => \Illuminate\Support\Facades\DB::raw("price_hpp_snapshot - {$amount}"),
            'updated_at'         => now(),
        ]);

        // 5. Catat Log ke database
        \Illuminate\Support\Facades\DB::table('bld_booking_logs')->insert([
            'booking_id'        => $booking->id,
            'action'            => 'POTONG_HPP_ONLY', // Tandain lognya murni cuma potong HPP
            'previous_price'    => $booking->selling_price,
            'discount_amount'   => $amount, // Ini nominal HPP yang dipotong
            'request_branch_id' => $request->request_branch_id,
            'created_by'        => $user->id,
            'created_at'        => now()
        ]);

        \Illuminate\Support\Facades\DB::commit();
        return back()->with('success', 'JEDER! Modal (HPP) berhasil diturunkan. Margin keuntungan paket ini bertambah!');

    } catch (\Exception $e) {
        \Illuminate\Support\Facades\DB::rollBack();
        return back()->with('error', 'GAGAL: ' . $e->getMessage());
    }
}
public function logs()
{
    $logs = DB::table('bld_booking_logs')
        ->join('bld_bookings', 'bld_booking_logs.booking_id', '=', 'bld_bookings.id')
        ->join('bld_jamaahs', 'bld_bookings.jamaah_id', '=', 'bld_jamaahs.id')
        ->join('bld_packages', 'bld_bookings.package_id', '=', 'bld_packages.id')
        ->join('bld_users', 'bld_booking_logs.created_by', '=', 'bld_users.id')
        ->leftJoin('bld_branches', 'bld_booking_logs.request_branch_id', '=', 'bld_branches.id')
        ->select(
            'bld_booking_logs.*',
            'bld_bookings.booking_code',
            'bld_jamaahs.name as jamaah_name',
            'bld_packages.name as package_name',
            'bld_packages.departure_date',
            'bld_users.name as admin_name',
            'bld_branches.name as branch_name'
        )
        ->orderBy('bld_booking_logs.created_at', 'desc')
        ->get();

    return view('bookings.logs', compact('logs'));
}
    public function addExtraItem(Request $request)
    {
        // 1. Validasi Inputan Form
        $request->validate([
            'booking_id' => 'required',
            'item_id'    => 'required',
            'qty'        => 'required|numeric|min:1',
        ]);

        try {
            DB::beginTransaction();

            // 2. Cek Master Barang (Pastikan barang ada dan stok cukup)
            $item = DB::table('bld_items')->where('id', $request->item_id)->first();

            if (!$item) {
                throw new \Exception('Pilih barang yang bener, Cuk! Barang tidak ditemukan di sistem.');
            }

            if ($item->stock < $request->qty) {
                throw new \Exception("Stok tidak cukup! Sisa stok {$item->name} cuma tinggal {$item->stock} pcs.");
            }

            // 3. Masukkan ke keranjang logistik Jamaah
            DB::table('bld_booking_logistics')->insert([
                'booking_id' => $request->booking_id,
                'item_id'    => $request->item_id,
                'qty'        => $request->qty,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            // 4. Potong stok asli di Gudang / Master Barang
            DB::table('bld_items')->where('id', $request->item_id)->decrement('stock', $request->qty);

            DB::commit();
            return back()->with('success', 'Berhasil! Perlengkapan berhasil diserahkan dan Stok Gudang otomatis terpotong!');

        } catch (\Exception $e) {
            DB::rollBack();
            return back()->with('error', 'GAGAL INPUT: ' . $e->getMessage());
        }
    }
    public function potongHargaDiskon(Request $request)
    {
        // Cuma Admin Pusat yang boleh ngasih diskon (biar margin gak dimainin cabang)
        $user = \Illuminate\Support\Facades\Auth::user();
        if ($user->branch_id != 1 && !is_null($user->branch_id)) {
            return back()->with('error', 'Akses Ditolak! Cuma Pusat yang bisa ACC Diskon Jual.');
        }

        $request->validate([
            'booking_id'        => 'required',
            'amount'            => 'required|numeric|min:1',
            'request_branch_id' => 'required'
        ]);

        try {
            DB::beginTransaction();

            $booking = DB::table('bld_bookings')->where('id', $request->booking_id)->first();
            if (!$booking) throw new \Exception('Data booking tidak ditemukan!');

            // JEDER! Proteksi biar Diskon gak bikin Harga Jual lebih rendah dari HPP (Rugi Bandar)
            $newSellingPrice = $booking->selling_price - $request->amount;
            if ($newSellingPrice < $booking->price_hpp_snapshot) {
                throw new \Exception('GAGAL! Diskon kegedean, Harga Jual jadi lebih rendah dari HPP (Rugi!).');
            }

            // 1. Update tabel bld_bookings (Hanya kurangin selling_price dan catat akumulasi discount)
            DB::table('bld_bookings')->where('id', $request->booking_id)->update([
                'selling_price' => $newSellingPrice,
                'discount'      => $booking->discount + $request->amount, // Akumulasi kalau udah pernah diskon
                'updated_at'    => now(),
            ]);

            // 2. Catat ke tabel Logs (Buku Dosa)
            DB::table('bld_booking_logs')->insert([
                'booking_id'        => $booking->id,
                'action'            => 'DISKON_JUAL', // Action-nya dibedain dari Potong HPP
                'previous_price'    => $booking->selling_price,
                'discount_amount'   => $request->amount,
                'request_branch_id' => $request->request_branch_id,
                'created_by'        => $user->id,
                'created_at'        => now()
            ]);

            DB::commit();
            return back()->with('success', 'JEDER! Diskon Jual berhasil diberikan, margin berhasil dipotong!');

        } catch (\Exception $e) {
            DB::rollBack();
            return back()->with('error', $e->getMessage());
        }
    }
}
