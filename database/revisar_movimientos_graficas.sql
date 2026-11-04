-- Consultas SOLO DE LECTURA para diagnosticar por qué los totales no coinciden con la gráfica.
-- Reemplaza 1 por el id del usuario que inicia sesión.
SET @usuario_id = 1;
SELECT 'Movimientos' origen, COUNT(*) registros, COALESCE(SUM(CASE WHEN type='income' THEN amount ELSE 0 END),0) ingresos, COALESCE(SUM(CASE WHEN type='expense' THEN amount ELSE 0 END),0) gastos, MIN(movement_date) fecha_inicial, MAX(movement_date) fecha_final FROM movements WHERE user_id=@usuario_id;
SELECT 'Gastos de presupuestos' origen, COUNT(*) registros, COALESCE(SUM(amount),0) gastos, MIN(expense_date) fecha_inicial, MAX(expense_date) fecha_final FROM budget_expenses WHERE user_id=@usuario_id;
SELECT 'Abonos de metas' origen, COUNT(*) registros, COALESCE(SUM(amount),0) ahorros, MIN(deposit_date) fecha_inicial, MAX(deposit_date) fecha_final FROM goal_deposits WHERE user_id=@usuario_id;
SELECT id,type,amount,category,movement_date,description FROM movements WHERE user_id=@usuario_id ORDER BY amount DESC LIMIT 20;
