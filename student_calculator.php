<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/functions.php';

require_role(['student']);

$pageTitle = 'Scientific Calculator';
$bodyPageClass = 'page-student-calculator';
$mainContainerClass = 'container-fluid px-3 px-lg-4 py-3 py-md-4 flex-grow-1 d-flex flex-column min-w-0 app-main';
require_once __DIR__ . '/includes/header.php';
?>
<div class="mb-3">
    <h1 class="h4 mb-1"><i class="fa-solid fa-calculator me-2 text-primary"></i>Scientific Calculator</h1>
    <p class="text-muted small mb-0">Full-featured scientific calculator with trigonometry, logarithms, powers, and more.</p>
</div>

<div class="sci-calc-wrapper d-flex justify-content-center">
<div class="sci-calc" id="sciCalc">
    <div class="sci-calc-display">
        <div class="sci-calc-expression" id="calcExpression">&nbsp;</div>
        <div class="sci-calc-result" id="calcResult">0</div>
    </div>
    <div class="sci-calc-mode-bar">
        <button type="button" class="sci-calc-mode-btn active" data-angle="deg">DEG</button>
        <button type="button" class="sci-calc-mode-btn" data-angle="rad">RAD</button>
        <span class="sci-calc-memory-indicator" id="calcMemInd"></span>
    </div>
    <div class="sci-calc-buttons">
        <!-- Row 1: Scientific functions -->
        <button type="button" class="sci-btn func" data-action="sin">sin</button>
        <button type="button" class="sci-btn func" data-action="cos">cos</button>
        <button type="button" class="sci-btn func" data-action="tan">tan</button>
        <button type="button" class="sci-btn func" data-action="log">log</button>
        <button type="button" class="sci-btn func" data-action="ln">ln</button>

        <!-- Row 2: More functions -->
        <button type="button" class="sci-btn func" data-action="sqrt">&#8730;</button>
        <button type="button" class="sci-btn func" data-action="cbrt">&#8731;</button>
        <button type="button" class="sci-btn func" data-action="pow">x<sup>y</sup></button>
        <button type="button" class="sci-btn func" data-action="square">x&sup2;</button>
        <button type="button" class="sci-btn func" data-action="inv">1/x</button>

        <!-- Row 3: Constants and parens -->
        <button type="button" class="sci-btn func" data-action="pi">&pi;</button>
        <button type="button" class="sci-btn func" data-action="e">e</button>
        <button type="button" class="sci-btn func" data-action="fact">n!</button>
        <button type="button" class="sci-btn paren" data-action="(">(</button>
        <button type="button" class="sci-btn paren" data-action=")">)</button>

        <!-- Row 4: 7 8 9 DEL AC -->
        <button type="button" class="sci-btn num" data-action="7">7</button>
        <button type="button" class="sci-btn num" data-action="8">8</button>
        <button type="button" class="sci-btn num" data-action="9">9</button>
        <button type="button" class="sci-btn del" data-action="del">DEL</button>
        <button type="button" class="sci-btn clear" data-action="ac">AC</button>

        <!-- Row 5: 4 5 6 * / -->
        <button type="button" class="sci-btn num" data-action="4">4</button>
        <button type="button" class="sci-btn num" data-action="5">5</button>
        <button type="button" class="sci-btn num" data-action="6">6</button>
        <button type="button" class="sci-btn op" data-action="*">&times;</button>
        <button type="button" class="sci-btn op" data-action="/">&divide;</button>

        <!-- Row 6: 1 2 3 + - -->
        <button type="button" class="sci-btn num" data-action="1">1</button>
        <button type="button" class="sci-btn num" data-action="2">2</button>
        <button type="button" class="sci-btn num" data-action="3">3</button>
        <button type="button" class="sci-btn op" data-action="+">+</button>
        <button type="button" class="sci-btn op" data-action="-">&minus;</button>

        <!-- Row 7: 0 . +/- % = -->
        <button type="button" class="sci-btn num" data-action="0">0</button>
        <button type="button" class="sci-btn num" data-action=".">.</button>
        <button type="button" class="sci-btn func" data-action="negate">+/&minus;</button>
        <button type="button" class="sci-btn func" data-action="percent">%</button>
        <button type="button" class="sci-btn equals" data-action="=">=</button>
    </div>
    <div class="sci-calc-history-toggle">
        <button type="button" class="btn btn-sm btn-outline-secondary rounded-pill" id="calcHistToggle">
            <i class="fa-solid fa-clock-rotate-left me-1"></i>History
        </button>
    </div>
    <div class="sci-calc-history" id="calcHistory" style="display:none;">
        <div class="sci-calc-history-list" id="calcHistoryList"></div>
        <button type="button" class="btn btn-sm btn-outline-danger rounded-pill mt-2" id="calcHistClear">Clear history</button>
    </div>
</div>
</div>

<style>
.sci-calc-wrapper {
    padding-bottom: 2rem;
}
.sci-calc {
    width: 100%;
    max-width: 420px;
    background: #1e293b;
    border-radius: 20px;
    padding: 20px;
    box-shadow: 0 20px 60px rgba(0,0,0,0.3), 0 0 0 1px rgba(255,255,255,0.05);
    font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', system-ui, sans-serif;
}
.sci-calc-display {
    background: #0f172a;
    border-radius: 14px;
    padding: 16px 20px;
    margin-bottom: 14px;
    min-height: 90px;
    display: flex;
    flex-direction: column;
    justify-content: flex-end;
    align-items: flex-end;
    overflow: hidden;
    border: 1px solid rgba(255,255,255,0.06);
}
.sci-calc-expression {
    font-size: 0.85rem;
    color: #94a3b8;
    word-break: break-all;
    text-align: right;
    width: 100%;
    min-height: 1.2em;
    line-height: 1.3;
}
.sci-calc-result {
    font-size: 2.2rem;
    font-weight: 700;
    color: #f1f5f9;
    word-break: break-all;
    text-align: right;
    width: 100%;
    line-height: 1.2;
    margin-top: 4px;
}
.sci-calc-mode-bar {
    display: flex;
    align-items: center;
    gap: 6px;
    margin-bottom: 12px;
    padding: 0 2px;
}
.sci-calc-mode-btn {
    background: #334155;
    border: none;
    color: #94a3b8;
    font-size: 0.7rem;
    font-weight: 700;
    padding: 4px 10px;
    border-radius: 6px;
    cursor: pointer;
    letter-spacing: 0.05em;
    transition: all 0.15s;
}
.sci-calc-mode-btn.active {
    background: #3b82f6;
    color: #fff;
}
.sci-calc-memory-indicator {
    margin-left: auto;
    font-size: 0.7rem;
    color: #fbbf24;
    font-weight: 600;
}
.sci-calc-buttons {
    display: grid;
    grid-template-columns: repeat(5, 1fr);
    gap: 8px;
}
.sci-btn {
    border: none;
    border-radius: 12px;
    padding: 14px 4px;
    font-size: 0.92rem;
    font-weight: 600;
    cursor: pointer;
    transition: all 0.12s ease;
    display: flex;
    align-items: center;
    justify-content: center;
    user-select: none;
    -webkit-tap-highlight-color: transparent;
    line-height: 1;
}
.sci-btn.num {
    background: #334155;
    color: #f1f5f9;
}
.sci-btn.num:hover { background: #475569; }
.sci-btn.num:active { background: #1e293b; transform: scale(0.95); }

.sci-btn.op {
    background: #1d4ed8;
    color: #fff;
}
.sci-btn.op:hover { background: #2563eb; }
.sci-btn.op:active { background: #1e40af; transform: scale(0.95); }

.sci-btn.func {
    background: #1e3a5f;
    color: #93c5fd;
}
.sci-btn.func:hover { background: #1e4a7a; }
.sci-btn.func:active { background: #172e4a; transform: scale(0.95); }

.sci-btn.paren {
    background: #1e3a5f;
    color: #93c5fd;
}
.sci-btn.paren:hover { background: #1e4a7a; }

.sci-btn.del {
    background: #7c2d12;
    color: #fecaca;
}
.sci-btn.del:hover { background: #9a3412; }

.sci-btn.clear {
    background: #991b1b;
    color: #fff;
}
.sci-btn.clear:hover { background: #b91c1c; }

.sci-btn.equals {
    background: #16a34a;
    color: #fff;
    font-size: 1.2rem;
}
.sci-btn.equals:hover { background: #15803d; }
.sci-btn.equals:active { background: #166534; transform: scale(0.95); }

.sci-calc-history-toggle {
    text-align: center;
    margin-top: 14px;
}
.sci-calc-history-toggle .btn {
    color: #94a3b8;
    border-color: #475569;
    font-size: 0.78rem;
}
.sci-calc-history-toggle .btn:hover {
    color: #f1f5f9;
    border-color: #64748b;
    background: #334155;
}
.sci-calc-history {
    margin-top: 12px;
    background: #0f172a;
    border-radius: 12px;
    padding: 12px 14px;
    max-height: 200px;
    overflow-y: auto;
    border: 1px solid rgba(255,255,255,0.06);
}
.sci-calc-history-list {
    font-size: 0.8rem;
    color: #94a3b8;
}
.sci-calc-history-list .hist-item {
    padding: 5px 0;
    border-bottom: 1px solid #1e293b;
    cursor: pointer;
    transition: color 0.15s;
}
.sci-calc-history-list .hist-item:hover { color: #e2e8f0; }
.sci-calc-history-list .hist-item:last-child { border-bottom: none; }
.sci-calc-history-list .hist-expr { color: #64748b; }
.sci-calc-history-list .hist-ans { color: #93c5fd; font-weight: 600; margin-left: 6px; }

@media (max-width: 480px) {
    .sci-calc { padding: 14px; border-radius: 16px; }
    .sci-calc-buttons { gap: 5px; }
    .sci-btn { padding: 12px 2px; font-size: 0.82rem; border-radius: 10px; }
    .sci-calc-result { font-size: 1.8rem; }
}
</style>

<script>
(function() {
    var angleMode = 'deg';
    var expression = '';
    var resultEl = document.getElementById('calcResult');
    var exprEl = document.getElementById('calcExpression');
    var histList = document.getElementById('calcHistoryList');
    var histPanel = document.getElementById('calcHistory');
    var histToggle = document.getElementById('calcHistToggle');
    var histClear = document.getElementById('calcHistClear');
    var history = JSON.parse(localStorage.getItem('sci_calc_history') || '[]');
    var lastAnswer = null;

    function toRad(deg) { return deg * Math.PI / 180; }
    function toDeg(rad) { return rad * 180 / Math.PI; }

    function factorial(n) {
        if (n < 0) return NaN;
        if (n === 0 || n === 1) return 1;
        if (n > 170) return Infinity;
        var r = 1;
        for (var i = 2; i <= n; i++) r *= i;
        return r;
    }

    function formatNum(n) {
        if (typeof n !== 'number' || isNaN(n)) return 'Error';
        if (!isFinite(n)) return n > 0 ? 'Infinity' : '-Infinity';
        var s = n.toPrecision(12);
        return parseFloat(s).toString();
    }

    function evalExpression(expr) {
        expr = expr.replace(/π/g, '(' + Math.PI + ')');
        expr = expr.replace(/e(?![xp])/g, '(' + Math.E + ')');

        expr = expr.replace(/(\d+(?:\.\d+)?)!/g, function(_, num) {
            return 'factorial(' + num + ')';
        });

        expr = expr.replace(/sin\(([^)]+)\)/g, function(_, inner) {
            if (angleMode === 'deg') return 'Math.sin(toRad(' + inner + '))';
            return 'Math.sin(' + inner + ')';
        });
        expr = expr.replace(/cos\(([^)]+)\)/g, function(_, inner) {
            if (angleMode === 'deg') return 'Math.cos(toRad(' + inner + '))';
            return 'Math.cos(' + inner + ')';
        });
        expr = expr.replace(/tan\(([^)]+)\)/g, function(_, inner) {
            if (angleMode === 'deg') return 'Math.tan(toRad(' + inner + '))';
            return 'Math.tan(' + inner + ')';
        });
        expr = expr.replace(/log\(([^)]+)\)/g, 'Math.log10($1)');
        expr = expr.replace(/ln\(([^)]+)\)/g, 'Math.log($1)');
        expr = expr.replace(/sqrt\(([^)]+)\)/g, 'Math.sqrt($1)');
        expr = expr.replace(/cbrt\(([^)]+)\)/g, 'Math.cbrt($1)');

        var fn = new Function('factorial', 'toRad', 'toDeg', 'return (' + expr + ');');
        return fn(factorial, toRad, toDeg);
    }

    function updateDisplay() {
        exprEl.textContent = expression || '\u00a0';
        if (lastAnswer !== null) {
            resultEl.textContent = formatNum(lastAnswer);
        }
    }

    function addHistory(expr, ans) {
        history.unshift({ expr: expr, ans: formatNum(ans) });
        if (history.length > 50) history.pop();
        localStorage.setItem('sci_calc_history', JSON.stringify(history));
        renderHistory();
    }

    function renderHistory() {
        if (!histList) return;
        histList.innerHTML = '';
        history.forEach(function(item) {
            var div = document.createElement('div');
            div.className = 'hist-item';
            div.innerHTML = '<span class="hist-expr">' + escapeHtml(item.expr) + '</span><span class="hist-ans">= ' + escapeHtml(item.ans) + '</span>';
            div.addEventListener('click', function() {
                expression = item.ans;
                lastAnswer = parseFloat(item.ans);
                updateDisplay();
            });
            histList.appendChild(div);
        });
    }

    function escapeHtml(s) {
        var d = document.createElement('div');
        d.textContent = s;
        return d.innerHTML;
    }

    function handleAction(action) {
        switch (action) {
            case 'ac':
                expression = '';
                lastAnswer = null;
                resultEl.textContent = '0';
                exprEl.textContent = '\u00a0';
                return;
            case 'del':
                expression = expression.slice(0, -1);
                updateDisplay();
                return;
            case '=':
                try {
                    var result = evalExpression(expression);
                    if (typeof result === 'number') {
                        addHistory(expression, result);
                        lastAnswer = result;
                        exprEl.textContent = expression;
                        resultEl.textContent = formatNum(result);
                        expression = formatNum(result);
                    }
                } catch (e) {
                    resultEl.textContent = 'Error';
                    lastAnswer = null;
                }
                return;
            case 'sin':
            case 'cos':
            case 'tan':
            case 'log':
            case 'ln':
            case 'sqrt':
            case 'cbrt':
                expression += action + '(';
                break;
            case 'pow':
                expression += '**';
                break;
            case 'square':
                expression += '**2';
                break;
            case 'inv':
                expression = '1/(' + expression + ')';
                break;
            case 'pi':
                expression += 'π';
                break;
            case 'e':
                expression += 'e';
                break;
            case 'fact':
                expression += '!';
                break;
            case 'negate':
                if (expression && expression.charAt(0) === '-') {
                    expression = expression.substring(1);
                } else if (expression) {
                    expression = '-' + expression;
                }
                break;
            case 'percent':
                try {
                    var pv = evalExpression(expression);
                    if (typeof pv === 'number') {
                        expression = formatNum(pv / 100);
                        lastAnswer = pv / 100;
                    }
                } catch (e) { /* ignore */ }
                break;
            default:
                expression += action;
                break;
        }
        updateDisplay();
    }

    document.querySelectorAll('#sciCalc .sci-btn').forEach(function(btn) {
        btn.addEventListener('click', function() {
            var action = btn.getAttribute('data-action');
            if (action) handleAction(action);
        });
    });

    document.querySelectorAll('#sciCalc .sci-calc-mode-btn').forEach(function(btn) {
        btn.addEventListener('click', function() {
            angleMode = btn.getAttribute('data-angle') || 'deg';
            document.querySelectorAll('#sciCalc .sci-calc-mode-btn').forEach(function(b) {
                b.classList.toggle('active', b === btn);
            });
        });
    });

    if (histToggle) {
        histToggle.addEventListener('click', function() {
            var showing = histPanel.style.display !== 'none';
            histPanel.style.display = showing ? 'none' : 'block';
        });
    }

    if (histClear) {
        histClear.addEventListener('click', function() {
            history = [];
            localStorage.setItem('sci_calc_history', JSON.stringify(history));
            renderHistory();
        });
    }

    document.addEventListener('keydown', function(e) {
        if (e.target.tagName === 'INPUT' || e.target.tagName === 'TEXTAREA') return;
        var key = e.key;
        if ('0123456789.+-*/()'.indexOf(key) !== -1) {
            e.preventDefault();
            handleAction(key);
        } else if (key === 'Enter' || key === '=') {
            e.preventDefault();
            handleAction('=');
        } else if (key === 'Backspace') {
            e.preventDefault();
            handleAction('del');
        } else if (key === 'Escape') {
            e.preventDefault();
            handleAction('ac');
        } else if (key === '^') {
            e.preventDefault();
            handleAction('pow');
        }
    });

    renderHistory();
})();
</script>
<?php require_once __DIR__ . '/includes/footer.php'; ?>
