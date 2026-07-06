<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class BranchController extends Controller
{
    public function index()
    {
        // Ambil data, urutkan ID terbaru
        $branches = DB::table('bld_branches')->orderBy('id', 'desc')->get();

        return view('branches.index', compact('branches'));
    }

    public function create()
    {
        return view('branches.form', [
            'branch' => null,
            'title'  => 'Tambah Cabang / Mitra Baru'
        ]);
    }

    public function store(Request $request)
    {
        $request->validate([
            'name'                => 'required|min:3', // Kolom DB lu 'name'
            'branch_type'         => 'required|in:kantor_cabang,mitra_perorangan',
            'phone'               => 'nullable',
            'address'             => 'nullable',
            'bank_name'           => 'nullable',
            'bank_account_number' => 'nullable',
            'bank_account_name'   => 'nullable',
        ]);

        try {
            DB::table('bld_branches')->insert([
                'name'                => $request->name, // Pakai 'name' sesuai DB lu
                'branch_type'         => $request->branch_type,
                'phone'               => $request->phone,
                'address'             => $request->address,
                'bank_name'           => $request->bank_name,
                'bank_account_number' => $request->bank_account_number,
                'bank_account_name'   => $request->bank_account_name,
                'created_at'          => now(),
                'updated_at'          => now(),
            ]);

            return redirect()->route('cabang.index')->with('success', 'Cabang berhasil ditambahkan!');
        } catch (\Exception $e) {
            return back()->with('error', 'Gagal simpan: ' . $e->getMessage());
        }
    }

    public function edit($id)
    {
        $branch = DB::table('bld_branches')->where('id', $id)->first();

        if (!$branch) return redirect()->route('cabang.index')->with('error', 'Data tidak ditemukan!');

        return view('branches.form', [
            'branch' => $branch,
            'title'  => 'Edit Cabang: ' . $branch->name
        ]);
    }

    public function update(Request $request, $id)
    {
        $request->validate([
            'name'                => 'required|min:3',
            'branch_type'         => 'required|in:kantor_cabang,mitra_perorangan',
            'phone'               => 'nullable',
            'address'             => 'nullable',
            'bank_name'           => 'nullable',
            'bank_account_number' => 'nullable',
            'bank_account_name'   => 'nullable',
        ]);

        try {
            DB::table('bld_branches')->where('id', $id)->update([
                'name'                => $request->name,
                'branch_type'         => $request->branch_type,
                'phone'               => $request->phone,
                'address'             => $request->address,
                'bank_name'           => $request->bank_name,
                'bank_account_number' => $request->bank_account_number,
                'bank_account_name'   => $request->bank_account_name,
                'updated_at'          => now(),
            ]);

            return redirect()->route('cabang.index')->with('success', 'Cabang berhasil diupdate!');
        } catch (\Exception $e) {
            return back()->with('error', 'Gagal update: ' . $e->getMessage());
        }
    }

    public function destroy($id)
    {
        try {
            // Cek biar nggak error kalau cabang sudah ada transaksi
            $hasBookings = DB::table('bld_bookings')->where('branch_id', $id)->exists();
            if ($hasBookings) {
                return back()->with('error', 'Gak bisa dihapus Cuk! Cabang ini sudah ada data jamaahnya.');
            }

            DB::table('bld_branches')->where('id', $id)->delete();
            return redirect()->route('cabang.index')->with('success', 'Cabang dihapus!');
        } catch (\Exception $e) {
            return back()->with('error', $e->getMessage());
        }
    }
}
