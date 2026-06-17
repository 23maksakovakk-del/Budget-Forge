#!/usr/bin/env node
/**
 * budget_manager.js - Бюджетный менеджер на JavaScript (Node.js CLI)
 */
const fs = require('fs');
const path = require('path');
const { program } = require('commander');
const { v4: uuidv4 } = require('uuid');

const DATA_FILE = path.join(__dirname, 'budget_data.json');

class Transaction {
    constructor(type, category, amount, date, description = '') {
        this.id = uuidv4();
        this.type = type;
        this.category = category;
        this.amount = amount;
        this.date = date || new Date().toISOString().slice(0, 10);
        this.description = description;
    }
}

class Budget {
    constructor(category, month, amount) {
        this.category = category;
        this.month = month;
        this.amount = amount;
    }
}

class BudgetManager {
    constructor() {
        this.transactions = [];
        this.budgets = [];
        this.load();
    }

    load() {
        if (fs.existsSync(DATA_FILE)) {
            try {
                const data = JSON.parse(fs.readFileSync(DATA_FILE, 'utf8'));
                this.transactions = data.transactions || [];
                this.budgets = data.budgets || [];
            } catch { }
        }
    }

    save() {
        fs.writeFileSync(DATA_FILE, JSON.stringify({ transactions: this.transactions, budgets: this.budgets }, null, 2));
    }

    addTransaction(type, category, amount, date, description) {
        const t = new Transaction(type, category, amount, date, description);
        this.transactions.push(t);
        this.save();
        return t;
    }

    setBudget(category, month, amount) {
        this.budgets = this.budgets.filter(b => !(b.category === category && b.month === month));
        const b = new Budget(category, month, amount);
        this.budgets.push(b);
        this.save();
        return b;
    }

    getBudget(category, month) {
        const b = this.budgets.find(b => b.category === category && b.month === month);
        return b ? b.amount : null;
    }

    getTransactions(filter = {}) {
        let result = this.transactions;
        if (filter.type) result = result.filter(t => t.type === filter.type);
        if (filter.category) result = result.filter(t => t.category === filter.category);
        if (filter.dateFrom) result = result.filter(t => t.date >= filter.dateFrom);
        if (filter.dateTo) result = result.filter(t => t.date <= filter.dateTo);
        if (filter.minAmount !== undefined) result = result.filter(t => t.amount >= filter.minAmount);
        if (filter.maxAmount !== undefined) result = result.filter(t => t.amount <= filter.maxAmount);
        return result.sort((a, b) => a.date.localeCompare(b.date));
    }

    getStatistics(month) {
        const filtered = month ? this.getTransactions({ dateFrom: month + '-01', dateTo: month + '-31' }) : this.transactions;
        const totalIncome = filtered.filter(t => t.type === 'income').reduce((s, t) => s + t.amount, 0);
        const totalExpense = filtered.filter(t => t.type === 'expense').reduce((s, t) => s + t.amount, 0);
        const byCategory = {};
        filtered.filter(t => t.type === 'expense').forEach(t => {
            byCategory[t.category] = (byCategory[t.category] || 0) + t.amount;
        });
        const budgetProgress = {};
        this.budgets.forEach(b => {
            if (month && b.month !== month) return;
            const spent = byCategory[b.category] || 0;
            budgetProgress[b.category] = {
                budget: b.amount,
                spent: spent,
                remaining: b.amount - spent,
                percent: b.amount > 0 ? (spent / b.amount) * 100 : 0
            };
        });
        return {
            totalIncome,
            totalExpense,
            balance: totalIncome - totalExpense,
            byCategory,
            budgetProgress,
            count: filtered.length
        };
    }

    exportCSV(filepath) {
        const csv = ['ID,Type,Category,Amount,Date,Description'];
        this.transactions.forEach(t => {
            csv.push(`${t.id},${t.type},${t.category},${t.amount},${t.date},${t.description}`);
        });
        fs.writeFileSync(filepath, csv.join('\n'));
    }
}

program
    .command('add')
    .requiredOption('-t, --type <type>', 'income/expense')
    .requiredOption('-c, --category <category>', 'Категория')
    .requiredOption('-a, --amount <amount>', 'Сумма', parseFloat)
    .option('-d, --date <date>', 'Дата (ГГГГ-ММ-ДД)')
    .option('-D, --description <description>', 'Описание')
    .action((options) => {
        const manager = new BudgetManager();
        const tr = manager.addTransaction(options.type, options.category, options.amount, options.date, options.description);
        console.log(`✅ Добавлена транзакция ${tr.id}: ${tr.type} ${tr.amount} ${tr.category}`);
    });

program
    .command('set-budget')
    .requiredOption('-c, --category <category>')
    .requiredOption('-a, --amount <amount>', parseFloat)
    .option('-m, --month <month>', 'ГГГГ-ММ')
    .action((options) => {
        const manager = new BudgetManager();
        const month = options.month || new Date().toISOString().slice(0, 7);
        const b = manager.setBudget(options.category, month, options.amount);
        console.log(`✅ Бюджет для ${b.category} на ${b.month} установлен: ${b.amount}`);
    });

program
    .command('list')
    .option('--type <type>', 'income/expense')
    .option('--category <category>')
    .option('--from <dateFrom>')
    .option('--to <dateTo>')
    .option('--min <minAmount>', parseFloat)
    .option('--max <maxAmount>', parseFloat)
    .action((options) => {
        const manager = new BudgetManager();
        const list = manager.getTransactions(options);
        if (!list.length) { console.log('Нет записей.'); return; }
        console.log('ID      Type   Date       Category       Amount   Description');
        list.forEach(t => {
            console.log(`${t.id.slice(0,8)}  ${t.type.padEnd(6)}  ${t.date}  ${t.category.padEnd(12)}  ${t.amount.toFixed(2)}  ${t.description}`);
        });
    });

program
    .command('stats')
    .option('--month <month>', 'ГГГГ-ММ')
    .action((options) => {
        const manager = new BudgetManager();
        const stats = manager.getStatistics(options.month);
        console.log(`📊 Статистика ${options.month ? 'за ' + options.month : ''}`);
        console.log(`Доходы: ${stats.totalIncome.toFixed(2)}`);
        console.log(`Расходы: ${stats.totalExpense.toFixed(2)}`);
        console.log(`Баланс: ${stats.balance.toFixed(2)}`);
        if (Object.keys(stats.byCategory).length) {
            console.log('Расходы по категориям:');
            Object.entries(stats.byCategory).sort((a,b) => b[1]-a[1]).forEach(([cat, amt]) => {
                console.log(`  ${cat}: ${amt.toFixed(2)}`);
            });
        }
        if (Object.keys(stats.budgetProgress).length) {
            console.log('Прогресс бюджета:');
            Object.entries(stats.budgetProgress).forEach(([cat, prog]) => {
                console.log(`  ${cat}: ${prog.spent.toFixed(2)} / ${prog.budget.toFixed(2)} (${prog.percent.toFixed(1)}%)`);
            });
        }
    });

program
    .command('export')
    .requiredOption('-o, --output <file>', 'CSV файл')
    .action((options) => {
        const manager = new BudgetManager();
        manager.exportCSV(options.output);
        console.log(`Экспортировано в ${options.output}`);
    });

program
    .command('interactive')
    .description('Интерактивный режим')
    .action(() => {
        const manager = new BudgetManager();
        const readline = require('readline');
        const rl = readline.createInterface({ input: process.stdin, output: process.stdout });
        const prompt = (q) => new Promise(resolve => rl.question(q, resolve));

        (async function() {
            while (true) {
                console.log('\n💰 Бюджетный менеджер (интерактивный)');
                console.log('1. Добавить доход');
                console.log('2. Добавить расход');
                console.log('3. Установить бюджет');
                console.log('4. Показать транзакции');
                console.log('5. Статистика');
                console.log('6. Экспорт CSV');
                console.log('0. Выход');
                const choice = await prompt('Выберите действие: ');
                switch (choice.trim()) {
                    case '0': rl.close(); return;
                    case '1':
                    case '2': {
                        const type = choice === '1' ? 'income' : 'expense';
                        const cat = await prompt('Категория: ');
                        if (!cat) { console.log('Категория обязательна'); break; }
                        const amt = parseFloat(await prompt('Сумма: '));
                        if (isNaN(amt) || amt <= 0) { console.log('Неверная сумма'); break; }
                        let date = await prompt('Дата (ГГГГ-ММ-ДД, Enter сегодня): ');
                        if (!date) date = new Date().toISOString().slice(0,10);
                        const desc = await prompt('Описание: ');
                        const tr = manager.addTransaction(type, cat, amt, date, desc);
                        console.log(`✅ Добавлена транзакция ${tr.id}`);
                        if (type === 'expense') {
                            const month = date.slice(0,7);
                            const budget = manager.getBudget(cat, month);
                            if (budget !== null) {
                                const spent = manager.getTransactions({ type: 'expense', category: cat, dateFrom: month+'-01', dateTo: month+'-31' })
                                    .reduce((s, t) => s + t.amount, 0);
                                if (spent > budget) {
                                    console.log(`⚠️ ПРЕВЫШЕНИЕ БЮДЖЕТА! Категория ${cat}, лимит ${budget}, потрачено ${spent.toFixed(2)}`);
                                }
                            }
                        }
                        break;
                    }
                    case '3': {
                        const cat = await prompt('Категория: ');
                        if (!cat) { console.log('Категория обязательна'); break; }
                        const month = await prompt('Месяц (ГГГГ-ММ, Enter текущий): ') || new Date().toISOString().slice(0,7);
                        const amt = parseFloat(await prompt('Сумма бюджета: '));
                        if (isNaN(amt) || amt <= 0) { console.log('Неверная сумма'); break; }
                        const b = manager.setBudget(cat, month, amt);
                        console.log(`✅ Бюджет для ${b.category} на ${b.month} установлен: ${b.amount}`);
                        break;
                    }
                    case '4': {
                        const cat = await prompt('Категория (Enter пропустить): ') || undefined;
                        const type = await prompt('Тип (income/expense, Enter пропустить): ') || undefined;
                        const from = await prompt('Дата от (Enter пропустить): ') || undefined;
                        const to = await prompt('Дата до (Enter пропустить): ') || undefined;
                        const list = manager.getTransactions({ category: cat, type, dateFrom: from, dateTo: to });
                        if (!list.length) { console.log('Нет записей.'); break; }
                        console.log('ID      Type   Date       Category       Amount   Description');
                        list.forEach(t => {
                            console.log(`${t.id.slice(0,8)}  ${t.type.padEnd(6)}  ${t.date}  ${t.category.padEnd(12)}  ${t.amount.toFixed(2)}  ${t.description}`);
                        });
                        break;
                    }
                    case '5': {
                        const month = await prompt('Месяц (ГГГГ-ММ, Enter все): ') || undefined;
                        const stats = manager.getStatistics(month);
                        console.log(`📊 Статистика ${month ? 'за ' + month : 'всего'}`);
                        console.log(`Доходы: ${stats.totalIncome.toFixed(2)}`);
                        console.log(`Расходы: ${stats.totalExpense.toFixed(2)}`);
                        console.log(`Баланс: ${stats.balance.toFixed(2)}`);
                        if (Object.keys(stats.byCategory).length) {
                            console.log('Расходы по категориям:');
                            Object.entries(stats.byCategory).sort((a,b) => b[1]-a[1]).forEach(([cat, amt]) => {
                                console.log(`  ${cat}: ${amt.toFixed(2)}`);
                            });
                        }
                        if (Object.keys(stats.budgetProgress).length) {
                            console.log('Прогресс бюджета:');
                            Object.entries(stats.budgetProgress).forEach(([cat, prog]) => {
                                console.log(`  ${cat}: ${prog.spent.toFixed(2)} / ${prog.budget.toFixed(2)} (${prog.percent.toFixed(1)}%)`);
                            });
                        }
                        break;
                    }
                    case '6': {
                        const file = await prompt('Имя файла (CSV): ') || 'export.csv';
                        manager.exportCSV(file);
                        console.log(`Экспортировано в ${file}`);
                        break;
                    }
                    default: console.log('Неверный выбор');
                }
            }
        })();
    });

if (process.argv.length <= 2) process.argv.push('interactive');
program.parse(process.argv);
