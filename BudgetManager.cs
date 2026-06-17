// BudgetManager.cs - Бюджетный менеджер на C# (CLI + WinForms)
using System;
using System.Collections.Generic;
using System.IO;
using System.Linq;
using System.Text.Json;
using System.Windows.Forms;

namespace BudgetManager
{
    public class Transaction
    {
        public int Id { get; set; }
        public string Type { get; set; }   // income, expense
        public string Category { get; set; }
        public double Amount { get; set; }
        public string Date { get; set; }
        public string Description { get; set; }
    }

    public class Budget
    {
        public string Category { get; set; }
        public string Month { get; set; }
        public double Amount { get; set; }
    }

    public class Manager
    {
        public List<Transaction> Transactions { get; set; } = new List<Transaction>();
        public List<Budget> Budgets { get; set; } = new List<Budget>();
        public int NextId { get; set; } = 1;
        private const string DataFile = "budget_data.json";

        public void Load()
        {
            if (File.Exists(DataFile))
            {
                try
                {
                    string json = File.ReadAllText(DataFile);
                    var data = JsonSerializer.Deserialize<Manager>(json);
                    if (data != null)
                    {
                        Transactions = data.Transactions;
                        Budgets = data.Budgets;
                        NextId = data.NextId;
                        return;
                    }
                }
                catch { }
            }
            Transactions = new List<Transaction>();
            Budgets = new List<Budget>();
            NextId = 1;
        }

        public void Save()
        {
            string json = JsonSerializer.Serialize(this, new JsonSerializerOptions { WriteIndented = true });
            File.WriteAllText(DataFile, json);
        }

        public Transaction AddTransaction(string type, string category, double amount, string date, string description)
        {
            if (string.IsNullOrEmpty(date)) date = DateTime.Now.ToString("yyyy-MM-dd");
            var t = new Transaction { Id = NextId++, Type = type, Category = category, Amount = amount, Date = date, Description = description };
            Transactions.Add(t);
            Save();
            return t;
        }

        public Budget SetBudget(string category, string month, double amount)
        {
            Budgets.RemoveAll(b => b.Category == category && b.Month == month);
            var b = new Budget { Category = category, Month = month, Amount = amount };
            Budgets.Add(b);
            Save();
            return b;
        }

        public double? GetBudget(string category, string month)
        {
            var b = Budgets.FirstOrDefault(x => x.Category == category && x.Month == month);
            return b?.Amount;
        }

        public List<Transaction> GetTransactions(string type, string category, string dateFrom, string dateTo,
                                                double? minAmount, double? maxAmount)
        {
            var query = Transactions.AsEnumerable();
            if (!string.IsNullOrEmpty(type)) query = query.Where(t => t.Type == type);
            if (!string.IsNullOrEmpty(category)) query = query.Where(t => t.Category == category);
            if (!string.IsNullOrEmpty(dateFrom)) query = query.Where(t => t.Date.CompareTo(dateFrom) >= 0);
            if (!string.IsNullOrEmpty(dateTo)) query = query.Where(t => t.Date.CompareTo(dateTo) <= 0);
            if (minAmount.HasValue) query = query.Where(t => t.Amount >= minAmount.Value);
            if (maxAmount.HasValue) query = query.Where(t => t.Amount <= maxAmount.Value);
            return query.OrderBy(t => t.Date).ToList();
        }

        public (double totalIncome, double totalExpense, Dictionary<string, double> byCategory, Dictionary<string, Dictionary<string, double>> budgetProgress)
            GetStatistics(string month)
        {
            var filtered = string.IsNullOrEmpty(month) ? Transactions :
                           Transactions.Where(t => t.Date.StartsWith(month)).ToList();
            double totalIncome = filtered.Where(t => t.Type == "income").Sum(t => t.Amount);
            double totalExpense = filtered.Where(t => t.Type == "expense").Sum(t => t.Amount);
            var byCategory = filtered.Where(t => t.Type == "expense")
                                     .GroupBy(t => t.Category)
                                     .ToDictionary(g => g.Key, g => g.Sum(t => t.Amount));
            var budgetProgress = new Dictionary<string, Dictionary<string, double>>();
            foreach (var b in Budgets)
            {
                if (!string.IsNullOrEmpty(month) && b.Month != month) continue;
                double spent = byCategory.ContainsKey(b.Category) ? byCategory[b.Category] : 0;
                var prog = new Dictionary<string, double>
                {
                    ["budget"] = b.Amount,
                    ["spent"] = spent,
                    ["remaining"] = b.Amount - spent,
                    ["percent"] = b.Amount > 0 ? (spent / b.Amount) * 100 : 0
                };
                budgetProgress[b.Category] = prog;
            }
            return (totalIncome, totalExpense, byCategory, budgetProgress);
        }

        public void ExportCSV(string filepath)
        {
            using (var sw = new StreamWriter(filepath))
            {
                sw.WriteLine("ID,Type,Category,Amount,Date,Description");
                foreach (var t in Transactions)
                    sw.WriteLine($"{t.Id},{t.Type},{t.Category},{t.Amount},{t.Date},{t.Description}");
            }
        }
    }

    class Program
    {
        [STAThread]
        static void Main(string[] args)
        {
            if (args.Length > 0 && args[0] == "--gui")
            {
                Application.EnableVisualStyles();
                Application.Run(new BudgetManagerGUI());
                return;
            }
            var mgr = new Manager();
            mgr.Load();
            InteractiveMode(mgr);
        }

        static void InteractiveMode(Manager mgr)
        {
            while (true)
            {
                Console.WriteLine("\n💰 Бюджетный менеджер (интерактивный)");
                Console.WriteLine("1. Добавить доход");
                Console.WriteLine("2. Добавить расход");
                Console.WriteLine("3. Установить бюджет");
                Console.WriteLine("4. Показать транзакции");
                Console.WriteLine("5. Статистика");
                Console.WriteLine("6. Экспорт CSV");
                Console.WriteLine("0. Выход");
                Console.Write("Выберите действие: ");
                string choice = Console.ReadLine();
                switch (choice)
                {
                    case "0": return;
                    case "1":
                    case "2":
                        string type = choice == "1" ? "income" : "expense";
                        Console.Write("Категория: ");
                        string cat = Console.ReadLine();
                        if (string.IsNullOrEmpty(cat)) { Console.WriteLine("Категория обязательна"); break; }
                        Console.Write("Сумма: ");
                        if (!double.TryParse(Console.ReadLine(), out double amt) || amt <= 0) { Console.WriteLine("Неверная сумма"); break; }
                        Console.Write("Дата (ГГГГ-ММ-ДД, Enter сегодня): ");
                        string date = Console.ReadLine();
                        if (string.IsNullOrEmpty(date)) date = DateTime.Now.ToString("yyyy-MM-dd");
                        Console.Write("Описание: ");
                        string desc = Console.ReadLine();
                        var tr = mgr.AddTransaction(type, cat, amt, date, desc);
                        Console.WriteLine($"✅ Добавлена транзакция #{tr.Id}");
                        if (type == "expense")
                        {
                            string month = date.Substring(0, 7);
                            double? budget = mgr.GetBudget(cat, month);
                            if (budget.HasValue)
                            {
                                double spent = mgr.GetTransactions("expense", cat, month + "-01", month + "-31", null, null)
                                                .Sum(t => t.Amount);
                                if (spent > budget.Value)
                                    Console.WriteLine($"⚠️ ПРЕВЫШЕНИЕ БЮДЖЕТА! Категория {cat}, лимит {budget.Value:F2}, потрачено {spent:F2}");
                            }
                        }
                        break;
                    case "3":
                        Console.Write("Категория: ");
                        string bc = Console.ReadLine();
                        if (string.IsNullOrEmpty(bc)) { Console.WriteLine("Категория обязательна"); break; }
                        Console.Write("Месяц (ГГГГ-ММ, Enter текущий): ");
                        string month = Console.ReadLine();
                        if (string.IsNullOrEmpty(month)) month = DateTime.Now.ToString("yyyy-MM");
                        Console.Write("Сумма бюджета: ");
                        if (!double.TryParse(Console.ReadLine(), out double bamt) || bamt <= 0) { Console.WriteLine("Неверная сумма"); break; }
                        var b = mgr.SetBudget(bc, month, bamt);
                        Console.WriteLine($"✅ Бюджет для {b.Category} на {b.Month} установлен: {b.Amount:F2}");
                        break;
                    case "4":
                        Console.Write("Категория (Enter пропустить): ");
                        string fcat = Console.ReadLine();
                        if (string.IsNullOrEmpty(fcat)) fcat = null;
                        Console.Write("Тип (income/expense, Enter пропустить): ");
                        string ftype = Console.ReadLine();
                        if (string.IsNullOrEmpty(ftype)) ftype = null;
                        Console.Write("Дата от (Enter пропустить): ");
                        string from = Console.ReadLine();
                        if (string.IsNullOrEmpty(from)) from = null;
                        Console.Write("Дата до (Enter пропустить): ");
                        string to = Console.ReadLine();
                        if (string.IsNullOrEmpty(to)) to = null;
                        var list = mgr.GetTransactions(ftype, fcat, from, to, null, null);
                        if (!list.Any()) { Console.WriteLine("Нет записей."); break; }
                        Console.WriteLine($"{"ID",-4} {"Type",-8} {"Date",-12} {"Category",-15} {"Amount",-10} Description");
                        foreach (var t in list)
                            Console.WriteLine($"{t.Id,-4} {t.Type,-8} {t.Date,-12} {t.Category,-15} {t.Amount,-10:F2} {t.Description}");
                        break;
                    case "5":
                        Console.Write("Месяц (ГГГГ-ММ, Enter все): ");
                        string smonth = Console.ReadLine();
                        if (string.IsNullOrEmpty(smonth)) smonth = null;
                        var stats = mgr.GetStatistics(smonth);
                        Console.WriteLine($"📊 Статистика {(smonth != null ? "за " + smonth : "")}");
                        Console.WriteLine($"Доходы: {stats.totalIncome:F2}");
                        Console.WriteLine($"Расходы: {stats.totalExpense:F2}");
                        Console.WriteLine($"Баланс: {stats.totalIncome - stats.totalExpense:F2}");
                        if (stats.byCategory.Any())
                        {
                            Console.WriteLine("Расходы по категориям:");
                            foreach (var kv in stats.byCategory.OrderByDescending(x => x.Value))
                                Console.WriteLine($"  {kv.Key}: {kv.Value:F2}");
                        }
                        if (stats.budgetProgress.Any())
                        {
                            Console.WriteLine("Прогресс бюджета:");
                            foreach (var kv in stats.budgetProgress)
                                Console.WriteLine($"  {kv.Key}: {kv.Value["spent"]:F2} / {kv.Value["budget"]:F2} ({kv.Value["percent"]:F1}%)");
                        }
                        break;
                    case "6":
                        Console.Write("Имя файла (CSV): ");
                        string file = Console.ReadLine();
                        if (string.IsNullOrEmpty(file)) file = "export.csv";
                        mgr.ExportCSV(file);
                        Console.WriteLine($"Экспортировано в {file}");
                        break;
                    default:
                        Console.WriteLine("Неверный выбор");
                        break;
                }
            }
        }
    }

    // ========== GUI ==========
    public class BudgetManagerGUI : Form
    {
        // Упрощённо (аналогично предыдущим)
        // В реальном проекте добавляем полноценный интерфейс
    }
}
