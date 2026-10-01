<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Auth;
use App\Core\Db;
use App\Services\JobService;

final class JobController extends Controller
{
    public function index(): void
    {
        $user = Auth::requireGrant('jobs.read');
        $rows = Db::all('SELECT * FROM jobs ORDER BY id DESC LIMIT 200');
        JobService::process(5);
        $rows2 = Db::all('SELECT * FROM jobs ORDER BY id DESC LIMIT 200');
        $this->view('admin/jobs', ['rows' => $rows2]);
    }
}
