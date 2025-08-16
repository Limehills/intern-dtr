<?php

namespace App\Http\Controllers;

use App\Models\Date;
use App\Models\TimeLog;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Carbon;
use App\Models\User;
use Barryvdh\DomPDF\Facade\Pdf;

date_default_timezone_set('Asia/Manila');

class DateController extends Controller
{
    protected User $user;

    protected ?Date $log;

    public function __construct()
    {
        $this->user = auth()->user();

        $this->log = $this->user->dates()
            ->whereDate('time_in', now())
            ->first();
    }

    public function index()
    {
        return view('home', [
            'user'      => $this->user,
            'todaysLog' => $this->log,
        ]);
    }

    public function break()
    {
        if (!$this->log) {
            return redirect()->route('home')->with('error', 'You need to time in first.');
        }


        if (empty($this->log->break_in)) {
            $this->log->break_in = now();
            $this->log->save();

            return redirect()->route('home')->with('success', 'Break started!');
        }

        $this->log->break_out = now();
        $this->log->save();

        return redirect()->route('home')->with('success', 'Break ended!');
    }

    public function timeIn()
    {
        // condition para dili sigeg balik og time in
        if (!empty($this->log->time_in)) {
            return response()->json([
                'status' => 'error',
                'message' => 'You have already timed in today.',
            ]);
        }

        $this->log = $this->user->dates()->firstOrCreate([
            'time_in' => now(),
            'time_in_image' => request()->input('face_data', null),
        ]);

        return response()->json([
            'status' => 'success',
            'message' => 'Time In recorded!',
            'log' => $this->log,
        ]);
    }

    public function timeOut()
    {
        if (!empty($this->log->time_out)) {
            return response()->json([
                'status' => 'error',
                'message' => 'You have already timed out today.',
            ]);
        }

        $this->log->time_out = now();
        $this->log->time_out_image = request()->input('face_data', null);
        $this->log->save();

        $requiredHours = $this->user->hour ?? 0;
        $timeIn = $this->log->time_in;
        $timeOut = $this->log->time_out;
        $breakIn = $this->log->break_in;
        $breakOut = $this->log->break_out;

        if ($requiredHours > 0 && $timeIn) {
            $totalSeconds = $requiredHours * 3600;
            $workedSeconds = Carbon::parse($timeOut)->diffInSeconds(Carbon::parse($timeIn));

            if ($breakIn && $breakOut) {
                $breakSeconds = Carbon::parse($breakOut)->diffInSeconds(Carbon::parse($breakIn));
                $workedSeconds = max($workedSeconds - $breakSeconds, 0);
            }

            $remainingSeconds = max($totalSeconds - $workedSeconds, 0);
            $remainingHours = round($remainingSeconds / 3600, 2);

            $this->user->remaining_hours = $remainingHours;
            $this->user->save();
        }

        return response()->json([
            'status' => 'success',
            'message' => 'Time Out recorded!',
            'log' => $this->log,
        ]);
    }


    public function history()
    {

        $dtrs = $this->user->dates()
            ->orderByDesc('time_in')
            ->get();

        return view('history', compact('dtrs'));
    }


    public function users()
    {
        $users = User::all();
        return view('user', compact('users'));
    }

    public function userHistory($id)
    {
        $user = User::findOrFail($id);
        $dtrs = $user->dates()->orderByDesc('time_in')->get();

        $totalHoursWorked = 0;
        foreach ($dtrs as $dtr) {
            $totalHoursWorked += $dtr->diffInHours();
        }

        $requiredHours = $user->hour ?? 8;

        $remainingHours = round(max($requiredHours - $totalHoursWorked, 0), 2);
        $user->remaining_hours = $remainingHours;

        return view('user_history', compact('user', 'dtrs', 'totalHoursWorked', 'requiredHours'));
    }

    public function downloadUserHistoryPdf($id)
    {
        try {
            $user = User::findOrFail($id);

            // Get date range from request
            $startDate = request('start_date');
            $endDate = request('end_date');

            // Build query for DTR records
            $query = $user->dates();

            // Apply date filters if provided
            if ($startDate && $endDate) {
                $query->whereBetween('time_in', [$startDate . ' 00:00:00', $endDate . ' 23:59:59']);
            }

            $dtrs = $query->orderByDesc('time_in')->get();

            $totalHoursWorked = 0;
            foreach ($dtrs as $dtr) {
                $totalHoursWorked += $dtr->diffInHours();
            }

            $requiredHours = $user->hour ?? 8;
            $remainingHours = round(max($requiredHours - $totalHoursWorked, 0), 2);

            $pdf = PDF::loadView('pdf.user_history', compact('user', 'dtrs', 'totalHoursWorked', 'requiredHours', 'remainingHours'));

            // Generate filename with date range if specified
            $filename = $user->name . '_DTR_History';
            if ($startDate && $endDate) {
                $filename .= '_' . $startDate . '_to_' . $endDate;
            }
            $filename .= '_' . now()->format('Y-m-d') . '.pdf';

            return $pdf->download($filename);
        } catch (\Exception $e) {
            return redirect()->back()->with('error', 'Failed to generate PDF: ' . $e->getMessage());
        }
    }
}
