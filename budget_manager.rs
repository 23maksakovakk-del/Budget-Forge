// budget_manager.rs - Бюджетный менеджер на Rust (CLI)
use serde::{Serialize, Deserialize};
use std::collections::{HashMap, HashSet};
use std::fs;
use std::io::{self, Write, BufRead};
use std::path::Path;
use std::str::FromStr;
use chrono::NaiveDate;

#[derive(Serialize, Deserialize, Clone)]
struct Transaction {
    id: u32,
    ttype: String,      // income/expense
    category: String,
    amount: f64,
    date: String,
    description: String,
}

#[derive(Serialize, Deserialize, Clone)]
struct Budget {
    category: String,
    month: String,
    amount: f64,
}

#[derive(Serialize, Deserialize)]
struct Data {
    transactions: Vec<Transaction>,
    budgets: Vec<Budget>,
    next_id: u32,
}

impl Data {
    fn load() -> Self {
        let path = "budget_data.json";
        if Path::new(path).exists() {
            if let Ok(json) = fs::read_to_string(path) {
                if let Ok(data) = serde_json::from_str(&json) {
                    return data;
                }
            }
        }
        Data {
            transactions: vec![],
            budgets: vec![],
            next_id: 1,
        }
    }

    fn save(&self) {
        let json = serde_json::to_string_pretty(self).unwrap();
        fs::write("budget_data.json", json).unwrap();
    }
}

fn add_transaction(data: &mut Data, ttype: &str, category: &str, amount: f64, date: &str, description: &str) -> Transaction {
    let tr = Transaction {
        id: data.next_id,
        ttype: ttype.to_string(),
        category: category.to_string(),
        amount,
        date: date.to_string(),
        description: description.to_string(),
    };
    data.transactions.push(tr.clone());
    data.next_id += 1;
    data.save();
    tr
}

fn set_budget(data: &mut Data, category: &str, month: &str, amount: f64) -> Budget {
    data.budgets.retain(|b| !(b.category == category && b.month == month));
    let b = Budget {
        category: category.to_string(),
        month: month.to_string(),
        amount,
    };
    data.budgets.push(b.clone());
    data.save();
    b
}

fn get_budget(data: &Data, category: &str, month: &str) -> Option<&Budget> {
    data.budgets.iter().find(|b| b.category == category && b.month == month)
}

fn get_transactions(data: &Data, ttype: Option<&str>, category: Option<&str>,
                    date_from: Option<&str>, date_to: Option<&str>,
                    min_amount: Option<f64>, max_amount: Option<f64>) -> Vec<Transaction> {
    let mut res = data.transactions.clone();
    if let Some(t) = ttype {
        res.retain(|tr| tr.ttype == t);
    }
    if let Some(c) = category {
        res.retain(|tr| tr.category == c);
    }
    if let Some(d) = date_from {
        res.retain(|tr| tr.date >= d);
    }
    if let Some(d) = date_to {
        res.retain(|tr| tr.date <= d);
    }
    if let Some(m) = min_amount {
        res.retain(|tr| tr.amount >= m);
    }
    if let Some(m) = max_amount {
        res.retain(|tr| tr.amount <= m);
    }
    res.sort_by(|a, b| a.date.cmp(&b.date));
    res
}

fn get_statistics(data: &Data, month: Option<&str>) -> (f64, f64, HashMap<String, f64>, HashMap<String, HashMap<String, f64>>) {
    let filtered = if let Some(m) = month {
        get_transactions(data, None, None, Some(&format!("{}-01", m)), Some(&format!("{}-31", m)), None, None)
    } else {
        data.transactions.clone()
    };
    let total_income = filtered.iter().filter(|t| t.ttype == "income").map(|t| t.amount).sum();
    let total_expense = filtered.iter().filter(|t| t.ttype == "expense").map(|t| t.amount).sum();
    let mut by_category = HashMap::new();
    for t in filtered.iter().filter(|t| t.ttype == "expense") {
        *by_category.entry(t.category.clone()).or_insert(0.0) += t.amount;
    }
    let mut budget_progress = HashMap::new();
    for b in &data.budgets {
        if let Some(m) = month {
            if b.month != m { continue; }
        }
        let spent = *by_category.get(&b.category).unwrap_or(&0.0);
        let mut prog = HashMap::new();
        prog.insert("budget".to_string(), b.amount);
        prog.insert("spent".to_string(), spent);
        prog.insert("remaining".to_string(), b.amount - spent);
        let percent = if b.amount > 0.0 { (spent / b.amount) * 100.0 } else { 0.0 };
        prog.insert("percent".to_string(), percent);
        budget_progress.insert(b.category.clone(), prog);
    }
    (total_income, total_expense, by_category, budget_progress)
}

fn main() {
    let args: Vec<String> = std::env::args().collect();
    if args.len() < 2 {
        interactive_mode();
        return;
    }
    let mut data = Data::load();
    match args[1].as_str() {
        "add" => {
            let mut ttype = String::new();
            let mut category = String::new();
            let mut amount = 0.0;
            let mut date = String::new();
            let mut description = String::new();
            let mut i = 2;
            while i < args.len() {
                match args[i].as_str() {
                    "--type" => { ttype = args[i+1].clone(); i += 2; }
                    "--category" => { category = args[i+1].clone(); i += 2; }
                    "--amount" => { amount = args[i+1].parse().unwrap_or(0.0); i += 2; }
                    "--date" => { date = args[i+1].clone(); i += 2; }
                    "--description" => { description = args[i+1].clone(); i += 2; }
                    _ => { i += 1; }
                }
            }
            if ttype.is_empty() || category.is_empty() || amount <= 0.0 {
                println!("Укажите --type, --category, --amount");
                return;
            }
            if date.is_empty() {
                date = chrono::Local::now().format("%Y-%m-%d").to_string();
            }
            let tr = add_transaction(&mut data, &ttype, &category, amount, &date, &description);
            println!("✅ Добавлена транзакция #{}: {} {:.2} {} ({})", tr.id, tr.ttype, tr.amount, tr.category, tr.date);
            if ttype == "expense" {
                let month = date[..7].to_string();
                if let Some(b) = get_budget(&data, &category, &month) {
                    let spent = get_transactions(&data, Some("expense"), Some(&category), Some(&format!("{}-01", month)), Some(&format!("{}-31", month)), None, None)
                        .iter().map(|t| t.amount).sum::<f64>();
                    if spent > b.amount {
                        println!("⚠️ ПРЕВЫШЕНИЕ БЮДЖЕТА! Категория {}, лимит {:.2}, потрачено {:.2}", category, b.amount, spent);
                    }
                }
            }
        }
        "set-budget" => {
            let mut category = String::new();
            let mut month = String::new();
            let mut amount = 0.0;
            let mut i = 2;
            while i < args.len() {
                match args[i].as_str() {
                    "--category" => { category = args[i+1].clone(); i += 2; }
                    "--month" => { month = args[i+1].clone(); i += 2; }
                    "--amount" => { amount = args[i+1].parse().unwrap_or(0.0); i += 2; }
                    _ => { i += 1; }
                }
            }
            if category.is_empty() || amount <= 0.0 {
                println!("Укажите --category, --amount");
                return;
            }
            if month.is_empty() {
                month = chrono::Local::now().format("%Y-%m").to_string();
            }
            let b = set_budget(&mut data, &category, &month, amount);
            println!("✅ Бюджет для {} на {} установлен: {:.2}", b.category, b.month, b.amount);
        }
        "list" => {
            let mut ttype = None;
            let mut category = None;
            let mut date_from = None;
            let mut date_to = None;
            let mut i = 2;
            while i < args.len() {
                match args[i].as_str() {
                    "--type" => { ttype = Some(args[i+1].clone()); i += 2; }
                    "--category" => { category = Some(args[i+1].clone()); i += 2; }
                    "--from" => { date_from = Some(args[i+1].clone()); i += 2; }
                    "--to" => { date_to = Some(args[i+1].clone()); i += 2; }
                    _ => { i += 1; }
                }
            }
            let list = get_transactions(&data, ttype.as_deref(), category.as_deref(),
                                        date_from.as_deref(), date_to.as_deref(), None, None);
            if list.is_empty() { println!("Нет записей."); return; }
            println!("{:<4} {:<8} {:<12} {:<15} {:<10} {}", "ID", "Type", "Date", "Category", "Amount", "Description");
            for t in list {
                println!("{:<4} {:<8} {:<12} {:<15} {:<10.2} {}", t.id, t.ttype, t.date, t.category, t.amount, t.description);
            }
        }
        "stats" => {
            let mut month = None;
            let mut i = 2;
            while i < args.len() {
                if args[i] == "--month" { month = Some(args[i+1].clone()); i += 2; } else { i += 1; }
            }
            let (income, expense, by_cat, budget_prog) = get_statistics(&data, month.as_deref());
            println!("📊 Статистика {}", if let Some(m) = month { format!("за {}", m) } else { String::new() });
            println!("Доходы: {:.2}", income);
            println!("Расходы: {:.2}", expense);
            println!("Баланс: {:.2}", income - expense);
            if !by_cat.is_empty() {
                println!("Расходы по категориям:");
                let mut sorted: Vec<_> = by_cat.into_iter().collect();
                sorted.sort_by(|a, b| b.1.partial_cmp(&a.1).unwrap());
                for (cat, amt) in sorted {
                    println!("  {}: {:.2}", cat, amt);
                }
            }
            if !budget_prog.is_empty() {
                println!("Прогресс бюджета:");
                for (cat, prog) in budget_prog {
                    println!("  {}: {:.2} / {:.2} ({:.1}%)", cat, prog["spent"], prog["budget"], prog["percent"]);
                }
            }
        }
        "export" => {
            let mut output = String::new();
            let mut i = 2;
            while i < args.len() {
                if args[i] == "--output" { output = args[i+1].clone(); i += 2; } else { i += 1; }
            }
            if output.is_empty() { println!("Укажите --output"); return; }
            use std::fs::File;
            use std::io::Write;
            let mut file = File::create(&output).unwrap();
            writeln!(file, "ID,Type,Category,Amount,Date,Description").unwrap();
            for t in &data.transactions {
                writeln!(file, "{},{},{},{:.2},{},{}", t.id, t.ttype, t.category, t.amount, t.date, t.description).unwrap();
            }
            println!("Экспортировано в {}", output);
        }
        _ => interactive_mode(),
    }
}

fn interactive_mode() {
    let mut data = Data::load();
    let stdin = io::stdin();
    let mut stdout = io::stdout();
    loop {
        println!("\n💰 Бюджетный менеджер (интерактивный)");
        println!("1. Добавить доход");
        println!("2. Добавить расход");
        println!("3. Установить бюджет");
        println!("4. Показать транзакции");
        println!("5. Статистика");
        println!("6. Экспорт CSV");
        println!("0. Выход");
        print!("Выберите действие: ");
        stdout.flush().unwrap();
        let mut choice = String::new();
        stdin.read_line(&mut choice).unwrap();
        match choice.trim() {
            "0" => break,
            "1" | "2" => {
                let ttype = if choice.trim() == "1" { "income" } else { "expense" };
                print!("Категория: ");
                stdout.flush().unwrap();
                let mut cat = String::new();
                stdin.read_line(&mut cat).unwrap();
                let cat = cat.trim();
                if cat.is_empty() { println!("Категория обязательна"); continue; }
                print!("Сумма: ");
                stdout.flush().unwrap();
                let mut amt = String::new();
                stdin.read_line(&mut amt).unwrap();
                let amount = match amt.trim().parse::<f64>() {
                    Ok(v) if v > 0.0 => v,
                    _ => { println!("Неверная сумма"); continue; }
                };
                print!("Дата (ГГГГ-ММ-ДД, Enter сегодня): ");
                stdout.flush().unwrap();
                let mut date = String::new();
                stdin.read_line(&mut date).unwrap();
                let date = if date.trim().is_empty() {
                    chrono::Local::now().format("%Y-%m-%d").to_string()
                } else {
                    date.trim().to_string()
                };
                print!("Описание: ");
                stdout.flush().unwrap();
                let mut desc = String::new();
                stdin.read_line(&mut desc).unwrap();
                let tr = add_transaction(&mut data, ttype, cat, amount, &date, desc.trim());
                println!("✅ Добавлена транзакция #{}", tr.id);
                if ttype == "expense" {
                    let month = date[..7].to_string();
                    if let Some(b) = get_budget(&data, cat, &month) {
                        let spent = get_transactions(&data, Some("expense"), Some(cat), Some(&format!("{}-01", month)), Some(&format!("{}-31", month)), None, None)
                            .iter().map(|t| t.amount).sum::<f64>();
                        if spent > b.amount {
                            println!("⚠️ ПРЕВЫШЕНИЕ БЮДЖЕТА! Категория {}, лимит {:.2}, потрачено {:.2}", cat, b.amount, spent);
                        }
                    }
                }
            }
            "3" => {
                print!("Категория: ");
                stdout.flush().unwrap();
                let mut cat = String::new();
                stdin.read_line(&mut cat).unwrap();
                let cat = cat.trim();
                if cat.is_empty() { println!("Категория обязательна"); continue; }
                print!("Месяц (ГГГГ-ММ, Enter текущий): ");
                stdout.flush().unwrap();
                let mut month = String::new();
                stdin.read_line(&mut month).unwrap();
                let month = if month.trim().is_empty() {
                    chrono::Local::now().format("%Y-%m").to_string()
                } else {
                    month.trim().to_string()
                };
                print!("Сумма бюджета: ");
                stdout.flush().unwrap();
                let mut amt = String::new();
                stdin.read_line(&mut amt).unwrap();
                let amount = match amt.trim().parse::<f64>() {
                    Ok(v) if v > 0.0 => v,
                    _ => { println!("Неверная сумма"); continue; }
                };
                let b = set_budget(&mut data, cat, &month, amount);
                println!("✅ Бюджет для {} на {} установлен: {:.2}", b.category, b.month, b.amount);
            }
            "4" => {
                print!("Категория (Enter пропустить): ");
                stdout.flush().unwrap();
                let mut cat = String::new();
                stdin.read_line(&mut cat).unwrap();
                let cat = if cat.trim().is_empty() { None } else { Some(cat.trim()) };
                print!("Тип (income/expense, Enter пропустить): ");
                stdout.flush().unwrap();
                let mut ttype = String::new();
                stdin.read_line(&mut ttype).unwrap();
                let ttype = if ttype.trim().is_empty() { None } else { Some(ttype.trim()) };
                print!("Дата от (Enter пропустить): ");
                stdout.flush().unwrap();
                let mut from = String::new();
                stdin.read_line(&mut from).unwrap();
                let from = if from.trim().is_empty() { None } else { Some(from.trim()) };
                print!("Дата до (Enter пропустить): ");
                stdout.flush().unwrap();
                let mut to = String::new();
                stdin.read_line(&mut to).unwrap();
                let to = if to.trim().is_empty() { None } else { Some(to.trim()) };
                let list = get_transactions(&data, ttype, cat, from, to, None, None);
                if list.is_empty() { println!("Нет записей."); continue; }
                println!("{:<4} {:<8} {:<12} {:<15} {:<10} {}", "ID", "Type", "Date", "Category", "Amount", "Description");
                for t in list {
                    println!("{:<4} {:<8} {:<12} {:<15} {:<10.2} {}", t.id, t.ttype, t.date, t.category, t.amount, t.description);
                }
            }
            "5" => {
                print!("Месяц (ГГГГ-ММ, Enter все): ");
                stdout.flush().unwrap();
                let mut month = String::new();
                stdin.read_line(&mut month).unwrap();
                let month = if month.trim().is_empty() { None } else { Some(month.trim()) };
                let (income, expense, by_cat, budget_prog) = get_statistics(&data, month);
                println!("📊 Статистика {}", if let Some(m) = month { format!("за {}", m) } else { String::new() });
                println!("Доходы: {:.2}", income);
                println!("Расходы: {:.2}", expense);
                println!("Баланс: {:.2}", income - expense);
                if !by_cat.is_empty() {
                    println!("Расходы по категориям:");
                    let mut sorted: Vec<_> = by_cat.into_iter().collect();
                    sorted.sort_by(|a, b| b.1.partial_cmp(&a.1).unwrap());
                    for (cat, amt) in sorted {
                        println!("  {}: {:.2}", cat, amt);
                    }
                }
                if !budget_prog.is_empty() {
                    println!("Прогресс бюджета:");
                    for (cat, prog) in budget_prog {
                        println!("  {}: {:.2} / {:.2} ({:.1}%)", cat, prog["spent"], prog["budget"], prog["percent"]);
                    }
                }
            }
            "6" => {
                print!("Имя файла (CSV): ");
                stdout.flush().unwrap();
                let mut file = String::new();
                stdin.read_line(&mut file).unwrap();
                let file = if file.trim().is_empty() { "export.csv".to_string() } else { file.trim().to_string() };
                use std::fs::File;
                use std::io::Write;
                let mut f = File::create(&file).unwrap();
                writeln!(f, "ID,Type,Category,Amount,Date,Description").unwrap();
                for t in &data.transactions {
                    writeln!(f, "{},{},{},{:.2},{},{}", t.id, t.ttype, t.category, t.amount, t.date, t.description).unwrap();
                }
                println!("Экспортировано в {}", file);
            }
            _ => println!("Неверный выбор"),
        }
    }
}
