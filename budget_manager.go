// budget_manager.go - Бюджетный менеджер на Go (CLI)
package main

import (
	"bufio"
	"encoding/json"
	"flag"
	"fmt"
	"os"
	"sort"
	"strconv"
	"strings"
	"time"
)

type Transaction struct {
	ID          int     `json:"id"`
	Type        string  `json:"type"`   // income, expense
	Category    string  `json:"category"`
	Amount      float64 `json:"amount"`
	Date        string  `json:"date"`
	Description string  `json:"description"`
}

type Budget struct {
	Category string  `json:"category"`
	Month    string  `json:"month"` // YYYY-MM
	Amount   float64 `json:"amount"`
}

type Data struct {
	Transactions []Transaction `json:"transactions"`
	Budgets      []Budget      `json:"budgets"`
	NextID       int           `json:"next_id"`
}

const dataFile = "budget_data.json"

func loadData() *Data {
	var d Data
	file, err := os.ReadFile(dataFile)
	if err != nil {
		d.Transactions = []Transaction{}
		d.Budgets = []Budget{}
		d.NextID = 1
		return &d
	}
	err = json.Unmarshal(file, &d)
	if err != nil {
		d.Transactions = []Transaction{}
		d.Budgets = []Budget{}
		d.NextID = 1
	}
	return &d
}

func saveData(d *Data) {
	data, _ := json.MarshalIndent(d, "", "  ")
	os.WriteFile(dataFile, data, 0644)
}

func addTransaction(d *Data, ttype, category string, amount float64, date, description string) Transaction {
	if date == "" {
		date = time.Now().Format("2006-01-02")
	}
	tr := Transaction{
		ID:          d.NextID,
		Type:        ttype,
		Category:    category,
		Amount:      amount,
		Date:        date,
		Description: description,
	}
	d.Transactions = append(d.Transactions, tr)
	d.NextID++
	saveData(d)
	return tr
}

func setBudget(d *Data, category, month string, amount float64) Budget {
	// удаляем старый
	newBudgets := []Budget{}
	for _, b := range d.Budgets {
		if !(b.Category == category && b.Month == month) {
			newBudgets = append(newBudgets, b)
		}
	}
	b := Budget{Category: category, Month: month, Amount: amount}
	newBudgets = append(newBudgets, b)
	d.Budgets = newBudgets
	saveData(d)
	return b
}

func getBudget(d *Data, category, month string) *Budget {
	for _, b := range d.Budgets {
		if b.Category == category && b.Month == month {
			return &b
		}
	}
	return nil
}

func getTransactions(d *Data, ttype, category, dateFrom, dateTo string, minAmount, maxAmount *float64) []Transaction {
	res := d.Transactions
	if ttype != "" {
		res = filterByType(res, ttype)
	}
	if category != "" {
		res = filterByCategory(res, category)
	}
	if dateFrom != "" {
		res = filterByDateFrom(res, dateFrom)
	}
	if dateTo != "" {
		res = filterByDateTo(res, dateTo)
	}
	if minAmount != nil {
		res = filterByMinAmount(res, *minAmount)
	}
	if maxAmount != nil {
		res = filterByMaxAmount(res, *maxAmount)
	}
	sort.Slice(res, func(i, j int) bool { return res[i].Date < res[j].Date })
	return res
}

func filterByType(tr []Transaction, t string) []Transaction {
	var res []Transaction
	for _, t := range tr {
		if t.Type == t {
			res = append(res, t)
		}
	}
	return res
}
func filterByCategory(tr []Transaction, cat string) []Transaction {
	var res []Transaction
	for _, t := range tr {
		if t.Category == cat {
			res = append(res, t)
		}
	}
	return res
}
func filterByDateFrom(tr []Transaction, from string) []Transaction {
	var res []Transaction
	for _, t := range tr {
		if t.Date >= from {
			res = append(res, t)
		}
	}
	return res
}
func filterByDateTo(tr []Transaction, to string) []Transaction {
	var res []Transaction
	for _, t := range tr {
		if t.Date <= to {
			res = append(res, t)
		}
	}
	return res
}
func filterByMinAmount(tr []Transaction, min float64) []Transaction {
	var res []Transaction
	for _, t := range tr {
		if t.Amount >= min {
			res = append(res, t)
		}
	}
	return res
}
func filterByMaxAmount(tr []Transaction, max float64) []Transaction {
	var res []Transaction
	for _, t := range tr {
		if t.Amount <= max {
			res = append(res, t)
		}
	}
	return res
}

func getStatistics(d *Data, month string) (totalIncome, totalExpense float64, byCategory map[string]float64, budgetProgress map[string]map[string]float64) {
	filtered := d.Transactions
	if month != "" {
		filtered = getTransactions(d, "", "", month+"-01", month+"-31", nil, nil)
	}
	totalIncome = 0
	totalExpense = 0
	byCategory = make(map[string]float64)
	for _, t := range filtered {
		if t.Type == "income" {
			totalIncome += t.Amount
		} else {
			totalExpense += t.Amount
			byCategory[t.Category] += t.Amount
		}
	}
	budgetProgress = make(map[string]map[string]float64)
	for _, b := range d.Budgets {
		if month != "" && b.Month != month {
			continue
		}
		spent := byCategory[b.Category]
		progress := map[string]float64{
			"budget":   b.Amount,
			"spent":    spent,
			"remaining": b.Amount - spent,
			"percent":  0,
		}
		if b.Amount > 0 {
			progress["percent"] = (spent / b.Amount) * 100
		}
		budgetProgress[b.Category] = progress
	}
	return
}

func main() {
	var (
		cmd         string
		ttype       string
		category    string
		amount      float64
		date        string
		description string
		month       string
		from        string
		to          string
		minAmt      float64
		maxAmt      float64
		output      string
		id          int
	)
	flag.StringVar(&cmd, "cmd", "", "Команда: add, set-budget, list, stats, export")
	flag.StringVar(&ttype, "type", "", "income/expense")
	flag.StringVar(&category, "category", "", "Категория")
	flag.Float64Var(&amount, "amount", 0, "Сумма")
	flag.StringVar(&date, "date", "", "Дата")
	flag.StringVar(&description, "description", "", "Описание")
	flag.StringVar(&month, "month", "", "Месяц (ГГГГ-ММ)")
	flag.StringVar(&from, "from", "", "Дата от")
	flag.StringVar(&to, "to", "", "Дата до")
	flag.Float64Var(&minAmt, "min", 0, "Мин. сумма")
	flag.Float64Var(&maxAmt, "max", 0, "Макс. сумма")
	flag.StringVar(&output, "output", "", "Файл для экспорта")
	flag.IntVar(&id, "id", 0, "ID транзакции")
	flag.Parse()

	data := loadData()

	switch cmd {
	case "add":
		if ttype == "" || category == "" || amount <= 0 {
			fmt.Println("Укажите --type, --category, --amount")
			return
		}
		if date == "" {
			date = time.Now().Format("2006-01-02")
		}
		tr := addTransaction(data, ttype, category, amount, date, description)
		fmt.Printf("✅ Добавлена транзакция #%d: %s %.2f %s (%s)\n", tr.ID, tr.Type, tr.Amount, tr.Category, tr.Date)
		if ttype == "expense" {
			month := date[:7]
			b := getBudget(data, category, month)
			if b != nil {
				spent := 0.0
				for _, t := range getTransactions(data, "expense", category, month+"-01", month+"-31", nil, nil) {
					spent += t.Amount
				}
				if spent > b.Amount {
					fmt.Printf("⚠️ ПРЕВЫШЕНИЕ БЮДЖЕТА! Категория %s, лимит %.2f, потрачено %.2f\n", category, b.Amount, spent)
				}
			}
		}
	case "set-budget":
		if category == "" || amount <= 0 {
			fmt.Println("Укажите --category, --amount")
			return
		}
		if month == "" {
			month = time.Now().Format("2006-01")
		}
		b := setBudget(data, category, month, amount)
		fmt.Printf("✅ Бюджет для %s на %s установлен: %.2f\n", b.Category, b.Month, b.Amount)
	case "list":
		var catPtr *string
		if category != "" {
			catPtr = &category
		}
		var typePtr *string
		if ttype != "" {
			typePtr = &ttype
		}
		var fromPtr, toPtr *string
		if from != "" {
			fromPtr = &from
		}
		if to != "" {
			toPtr = &to
		}
		var minPtr, maxPtr *float64
		if minAmt > 0 {
			minPtr = &minAmt
		}
		if maxAmt > 0 {
			maxPtr = &maxAmt
		}
		list := getTransactions(data, ttype, category, from, to, minPtr, maxPtr)
		if len(list) == 0 {
			fmt.Println("Нет записей.")
		} else {
			fmt.Printf("%-4s %-8s %-12s %-15s %-10s %s\n", "ID", "Type", "Date", "Category", "Amount", "Description")
			for _, t := range list {
				fmt.Printf("%-4d %-8s %-12s %-15s %-10.2f %s\n", t.ID, t.Type, t.Date, t.Category, t.Amount, t.Description)
			}
		}
	case "stats":
		totalIncome, totalExpense, byCategory, budgetProgress := getStatistics(data, month)
		fmt.Printf("📊 Статистика %s\n", map[bool]string{true: "за " + month, false: ""}[month != ""])
		fmt.Printf("Доходы: %.2f\n", totalIncome)
		fmt.Printf("Расходы: %.2f\n", totalExpense)
		fmt.Printf("Баланс: %.2f\n", totalIncome-totalExpense)
		if len(byCategory) > 0 {
			fmt.Println("Расходы по категориям:")
			type kv struct {
				Key   string
				Value float64
			}
			var sorted []kv
			for k, v := range byCategory {
				sorted = append(sorted, kv{k, v})
			}
			sort.Slice(sorted, func(i, j int) bool { return sorted[i].Value > sorted[j].Value })
			for _, kv := range sorted {
				fmt.Printf("  %s: %.2f\n", kv.Key, kv.Value)
			}
		}
		if len(budgetProgress) > 0 {
			fmt.Println("Прогресс бюджета:")
			for cat, prog := range budgetProgress {
				fmt.Printf("  %s: %.2f / %.2f (%.1f%%)\n", cat, prog["spent"], prog["budget"], prog["percent"])
			}
		}
	case "export":
		if output == "" {
			fmt.Println("Укажите --output")
			return
		}
		f, err := os.Create(output)
		if err != nil {
			fmt.Println("Ошибка создания файла:", err)
			return
		}
		defer f.Close()
		f.WriteString("ID,Type,Category,Amount,Date,Description\n")
		for _, t := range data.Transactions {
			f.WriteString(fmt.Sprintf("%d,%s,%s,%.2f,%s,%s\n", t.ID, t.Type, t.Category, t.Amount, t.Date, t.Description))
		}
		fmt.Printf("Экспортировано в %s\n", output)
	default:
		interactiveMode(data)
	}
}

func interactiveMode(d *Data) {
	scanner := bufio.NewScanner(os.Stdin)
	for {
		fmt.Println("\n💰 Бюджетный менеджер (интерактивный)")
		fmt.Println("1. Добавить доход")
		fmt.Println("2. Добавить расход")
		fmt.Println("3. Установить бюджет")
		fmt.Println("4. Показать транзакции")
		fmt.Println("5. Статистика")
		fmt.Println("6. Экспорт CSV")
		fmt.Println("0. Выход")
		fmt.Print("Выберите действие: ")
		scanner.Scan()
		choice := scanner.Text()
		switch choice {
		case "0":
			return
		case "1", "2":
			ttype := map[string]string{"1": "income", "2": "expense"}[choice]
			fmt.Print("Категория: ")
			scanner.Scan()
			cat := scanner.Text()
			if cat == "" {
				fmt.Println("Категория обязательна")
				continue
			}
			fmt.Print("Сумма: ")
			scanner.Scan()
			amtStr := scanner.Text()
			amt, err := strconv.ParseFloat(amtStr, 64)
			if err != nil || amt <= 0 {
				fmt.Println("Неверная сумма")
				continue
			}
			fmt.Print("Дата (ГГГГ-ММ-ДД, Enter сегодня): ")
			scanner.Scan()
			date := scanner.Text()
			if date == "" {
				date = time.Now().Format("2006-01-02")
			}
			fmt.Print("Описание: ")
			scanner.Scan()
			desc := scanner.Text()
			tr := addTransaction(d, ttype, cat, amt, date, desc)
			fmt.Printf("✅ Добавлена транзакция #%d\n", tr.ID)
			if ttype == "expense" {
				month := date[:7]
				b := getBudget(d, cat, month)
				if b != nil {
					spent := 0.0
					for _, t := range getTransactions(d, "expense", cat, month+"-01", month+"-31", nil, nil) {
						spent += t.Amount
					}
					if spent > b.Amount {
						fmt.Printf("⚠️ ПРЕВЫШЕНИЕ БЮДЖЕТА! Категория %s, лимит %.2f, потрачено %.2f\n", cat, b.Amount, spent)
					}
				}
			}
		case "3":
			fmt.Print("Категория: ")
			scanner.Scan()
			cat := scanner.Text()
			if cat == "" {
				fmt.Println("Категория обязательна")
				continue
			}
			fmt.Print("Месяц (ГГГГ-ММ, Enter текущий): ")
			scanner.Scan()
			month := scanner.Text()
			if month == "" {
				month = time.Now().Format("2006-01")
			}
			fmt.Print("Сумма бюджета: ")
			scanner.Scan()
			amtStr := scanner.Text()
			amt, err := strconv.ParseFloat(amtStr, 64)
			if err != nil || amt <= 0 {
				fmt.Println("Неверная сумма")
				continue
			}
			b := setBudget(d, cat, month, amt)
			fmt.Printf("✅ Бюджет для %s на %s установлен: %.2f\n", b.Category, b.Month, b.Amount)
		case "4":
			fmt.Print("Категория (Enter пропустить): ")
			scanner.Scan()
			cat := scanner.Text()
			if cat == "" {
				cat = ""
			}
			fmt.Print("Тип (income/expense, Enter пропустить): ")
			scanner.Scan()
			ttype := scanner.Text()
			if ttype == "" {
				ttype = ""
			}
			fmt.Print("Дата от (Enter пропустить): ")
			scanner.Scan()
			from := scanner.Text()
			if from == "" {
				from = ""
			}
			fmt.Print("Дата до (Enter пропустить): ")
			scanner.Scan()
			to := scanner.Text()
			if to == "" {
				to = ""
			}
			list := getTransactions(d, ttype, cat, from, to, nil, nil)
			if len(list) == 0 {
				fmt.Println("Нет записей.")
			} else {
				fmt.Printf("%-4s %-8s %-12s %-15s %-10s %s\n", "ID", "Type", "Date", "Category", "Amount", "Description")
				for _, t := range list {
					fmt.Printf("%-4d %-8s %-12s %-15s %-10.2f %s\n", t.ID, t.Type, t.Date, t.Category, t.Amount, t.Description)
				}
			}
		case "5":
			fmt.Print("Месяц (ГГГГ-ММ, Enter все): ")
			scanner.Scan()
			month := scanner.Text()
			if month == "" {
				month = ""
			}
			totalIncome, totalExpense, byCategory, budgetProgress := getStatistics(d, month)
			fmt.Printf("📊 Статистика %s\n", map[bool]string{true: "за " + month, false: ""}[month != ""])
			fmt.Printf("Доходы: %.2f\n", totalIncome)
			fmt.Printf("Расходы: %.2f\n", totalExpense)
			fmt.Printf("Баланс: %.2f\n", totalIncome-totalExpense)
			if len(byCategory) > 0 {
				fmt.Println("Расходы по категориям:")
				type kv struct {
					Key   string
					Value float64
				}
				var sorted []kv
				for k, v := range byCategory {
					sorted = append(sorted, kv{k, v})
				}
				sort.Slice(sorted, func(i, j int) bool { return sorted[i].Value > sorted[j].Value })
				for _, kv := range sorted {
					fmt.Printf("  %s: %.2f\n", kv.Key, kv.Value)
				}
			}
			if len(budgetProgress) > 0 {
				fmt.Println("Прогресс бюджета:")
				for cat, prog := range budgetProgress {
					fmt.Printf("  %s: %.2f / %.2f (%.1f%%)\n", cat, prog["spent"], prog["budget"], prog["percent"])
				}
			}
		case "6":
			fmt.Print("Имя файла (CSV): ")
			scanner.Scan()
			file := scanner.Text()
			if file == "" {
				file = "export.csv"
			}
			f, err := os.Create(file)
			if err != nil {
				fmt.Println("Ошибка создания файла:", err)
				continue
			}
			defer f.Close()
			f.WriteString("ID,Type,Category,Amount,Date,Description\n")
			for _, t := range d.Transactions {
				f.WriteString(fmt.Sprintf("%d,%s,%s,%.2f,%s,%s\n", t.ID, t.Type, t.Category, t.Amount, t.Date, t.Description))
			}
			fmt.Printf("Экспортировано в %s\n", file)
		default:
			fmt.Println("Неверный выбор")
		}
	}
}
