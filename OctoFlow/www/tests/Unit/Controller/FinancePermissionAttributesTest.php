<?php

namespace App\Tests\Unit\Controller;

use App\Controller\Finance\FinanceCatalogController;
use App\Controller\Finance\FinanceCurrencyController;
use App\Controller\Finance\FinanceDashboardController;
use App\Controller\Finance\FinanceDebtPlanController;
use App\Controller\Finance\FinanceEntryController;
use App\Controller\Finance\FinanceExportController;
use App\Controller\Finance\FinanceInstallmentController;
use App\Controller\Finance\FinanceInvestmentController;
use App\Controller\Finance\FinanceOpenFinanceController;
use App\Controller\Finance\FinanceRecurringController;
use App\Controller\Finance\FinanceTransferController;
use App\Finance\FinancePermissionResolver;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

final class FinancePermissionAttributesTest extends TestCase
{
    public function testEveryFinanceRouteActionDeclaresGranularFinancePermission(): void
    {
        foreach ($this->financeControllerClasses() as $controllerClass) {
            $reflectionClass = new \ReflectionClass($controllerClass);

            foreach ($reflectionClass->getMethods(\ReflectionMethod::IS_PUBLIC) as $method) {
                if ($method->isConstructor() || $method->getAttributes(Route::class) === []) {
                    continue;
                }

                $permissions = [];
                foreach ($method->getAttributes(IsGranted::class) as $attribute) {
                    $permission = $attribute->newInstance()->attribute;
                    if (is_string($permission)) {
                        $permissions[] = $permission;
                    }
                }

                $declaredFinancePermissions = array_values(array_intersect(
                    $permissions,
                    [FinancePermissionResolver::READ, FinancePermissionResolver::WRITE],
                ));

                $this->assertNotSame(
                    [],
                    $declaredFinancePermissions,
                    sprintf('%s::%s() must declare finance:read or finance:write.', $controllerClass, $method->getName()),
                );
            }
        }
    }

    /**
     * @return list<class-string>
     */
    private function financeControllerClasses(): array
    {
        return [
            FinanceCatalogController::class,
            FinanceCurrencyController::class,
            FinanceDashboardController::class,
            FinanceDebtPlanController::class,
            FinanceEntryController::class,
            FinanceExportController::class,
            FinanceInstallmentController::class,
            FinanceInvestmentController::class,
            FinanceOpenFinanceController::class,
            FinanceRecurringController::class,
            FinanceTransferController::class,
        ];
    }
}
