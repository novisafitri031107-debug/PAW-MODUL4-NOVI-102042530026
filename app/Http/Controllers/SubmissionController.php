<?php

namespace App\Http\Controllers;

use App\Models\Assignment;
use App\Models\Submission;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\StreamedResponse;

class SubmissionController extends Controller
{
    public function store(Request $request, Assignment $assignment): RedirectResponse
    {
        if (!Auth::check()) {
            return redirect()->route('login');
        }

        if (!Auth::user()->isMahasiswa()) {
            abort(403, 'Akses ditolak. Pengumpulan tugas hanya dapat dilakukan oleh Mahasiswa.');
        }

        $user = Auth::user();

        $enrollment = $assignment->course->students()->where('users.id', $user->id)->first();

        if (!$enrollment || $enrollment->pivot->status !== 'approved') {
            abort(403, 'Akses ditolak. Anda belum disetujui oleh Dosen untuk mengikuti mata kuliah tugas ini.');
        }

        if ($assignment->isOverdue()) {
            return back()->with('error', 'Gagal mengumpulkan: Batas waktu pengumpulan (deadline) tugas telah berakhir.');
        }

        $validated = $request->validate([
            'jawaban' => ['required', 'string', 'min:10', 'max:20000'],
        ], [
            'jawaban.required' => 'Teks jawaban tugas wajib diisi.',
            'jawaban.min' => 'Jawaban terlalu pendek. Tuliskan jawaban minimal 10 karakter.',
            'jawaban.max' => 'Jawaban melebihi batas maksimal 20.000 karakter.',
        ]);

        Submission::updateOrCreate(
            [
                'assignment_id' => $assignment->id,
                'user_id' => $user->id,
            ],
            [
                'jawaban' => $validated['jawaban'],
                'submitted_at' => now(),
            ]
        );

        return back()->with('success', 'Jawaban tugas Anda berhasil disimpan ke sistem.');
    }

    // TODO 5a (grade): Cek apakah dosen adalah pengampu mata kuliah, validasi nilai (0-100) & catatan_dosen, lalu update submission.
    // 1. Pastikan $submission->assignment->course->dosen_id === Auth::id(), jika tidak abort(403).
    // 2. Validasi input 'nilai' (required|integer|min:0|max:100) dan 'catatan_dosen' (nullable|string|max:1000).
    // 3. Simpan perubahan menggunakan $submission->update(...) dan redirect back() dengan pesan sukses.
    public function grade(Request $request, Submission $submission): RedirectResponse
    {
        // Pastikan dosen adalah pengampu mata kuliah
        if ($submission->assignment->course->dosen_id !== Auth::id()) {
            abort(403, 'Anda tidak memiliki akses untuk menilai tugas ini.');
        }

        // Validasi nilai dan catatan dosen
        $validated = $request->validate([
            'nilai' => ['required', 'integer', 'min:0', 'max:100'],
            'catatan_dosen' => ['nullable', 'string', 'max:1000'],
        ]);

        // Simpan nilai dan catatan
        $submission->update([
            'nilai' => $validated['nilai'],
            'catatan_dosen' => $validated['catatan_dosen'] ?? null,
        ]);

        return back()->with('success', 'Nilai mahasiswa berhasil disimpan.');
    }

    public function exportCsv(Assignment $assignment): StreamedResponse
    {
        // TODO 5b (exportCsv): Cek kepemilikan dosen, lalu gunakan response()->streamDownload() dan fputcsv() untuk mengunduh data nilai mahasiswa.
        // 1. Pastikan $assignment->course->dosen_id === Auth::id(), jika tidak abort(403).
        // 2. Muat relasi course.approvedStudents dan submissions dari $assignment.
        // 3. Kembalikan response()->streamDownload(...) yang menulis header & baris CSV menggunakan fopen('php://output', 'w') dan fputcsv().

        if ($assignment->course->dosen_id !== Auth::id()) {
            abort(403, 'Anda tidak memiliki akses untuk mengunduh data tugas ini.');
        }

        $students = $assignment->course->approvedStudents;

        $submissions = $assignment->submissions()
            ->with('user')
            ->get()
            ->keyBy('user_id');

        return response()->streamDownload(function () use ($students, $submissions) {
            $handle = fopen('php://output', 'w');

            fwrite($handle, "\xEF\xBB\xBF");

            fputcsv($handle, [
                'No',
                'Nama Mahasiswa',
                'Email',
                'NIM',
                'Status Pengumpulan',
                'Waktu Pengumpulan',
                'Jawaban',
                'Nilai',
                'Catatan Dosen',
            ]);

            foreach ($students as $index => $student) {
                $submission = $submissions->get($student->id);

                fputcsv($handle, [
                    $index + 1,
                    $student->name,
                    $student->email,
                    $student->nomor_induk ?? '-',
                    $submission ? 'Sudah Mengumpulkan' : 'Belum Mengumpulkan',
                    $submission?->submitted_at?->format('Y-m-d H:i:s') ?? '-',
                    $submission?->jawaban ?? '-',
                    $submission?->nilai ?? 'Belum Dinilai',
                    $submission?->catatan_dosen ?? '-',
                ]);
            }

            fclose($handle);
        }, 'rekap-nilai-' . $assignment->id . '.csv', [
            'Content-Type' => 'text/csv; charset=UTF-8',
        ]);
    }
}