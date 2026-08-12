<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Services\Reports\BusinessReportService;
use App\Services\Reports\TelecallerReportService;
use App\Support\ApiResponse;
use App\Support\Reporting\ReportPeriod;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Business reports (Phase 27, FR-RPT-02/04/05/06).
 *
 * Not data-scoped, deliberately, and gated on `reports.business` instead -
 * which Manager and above hold. A business dashboard IS the organisation-wide
 * view; scoping it to the caller's own leads would produce a number that looks
 * like a company total and is not one, which is worse than refusing the page.
 *
 * Telecaller performance (FR-RPT-01) is absent: it needs the attribution model
 * decided, and that decision affects pay (T-24, Phase 26).
 */
class ReportController extends Controller
{
    public function __construct(
        private readonly BusinessReportService $reports,
        private readonly TelecallerReportService $telecallers,
    ) {}

    /**
     * Telecaller performance (Phase 26, FR-RPT-01).
     *
     * The attribution model is returned alongside the rows, not left implicit.
     * "Who converted this?" has more than one defensible answer, and a report
     * that does not say which one it used invites an argument nobody can settle
     * (GLOSSARY 2.6, T-24).
     */
    public function telecallers(Request $request): JsonResponse
    {
        return ApiResponse::success(
            $this->telecallers->leaderboard(ReportPeriod::fromRequest($request)),
            'Telecaller performance retrieved.',
        );
    }

    /** The dashboard tiles. */
    public function summary(Request $request): JsonResponse
    {
        $period = ReportPeriod::fromRequest($request);

        return ApiResponse::success([
            'period' => $period->toArray(),
            'summary' => $this->reports->summary($period),
        ], 'Report generated.');
    }

    public function revenue(Request $request): JsonResponse
    {
        $period = ReportPeriod::fromRequest($request);

        return ApiResponse::success([
            'period' => $period->toArray(),
            'revenue' => $this->reports->revenue($period),
        ], 'Report generated.');
    }

    public function pipeline(Request $request): JsonResponse
    {
        $period = ReportPeriod::fromRequest($request);

        return ApiResponse::success([
            'period' => $period->toArray(),
            'conversion' => $this->reports->conversion($period),
        ], 'Report generated.');
    }

    public function products(Request $request): JsonResponse
    {
        $period = ReportPeriod::fromRequest($request);

        return ApiResponse::success([
            'period' => $period->toArray(),
            'products' => $this->reports->productPerformance($period),
        ], 'Report generated.');
    }

    public function sources(Request $request): JsonResponse
    {
        $period = ReportPeriod::fromRequest($request);

        return ApiResponse::success([
            'period' => $period->toArray(),
            'sources' => $this->reports->sourcePerformance($period),
        ], 'Report generated.');
    }
}
