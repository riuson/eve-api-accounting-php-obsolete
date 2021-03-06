<?php
    class Api_Transactions
    {
    	function ParseDate($str, $default)
    	{
    		$result = $default;
			date_default_timezone_set("Etc/Universal");
			if($str)
			{
				if(preg_match("/^\d\d\d\d-\d\d-\d\d$/i", $str) == 1)
				{
					try
					{
						$date = new DateTime($str);
						$result = $date->format("Y-m-d");
					}
					catch(Exception $exc)
					{
						$result = $default;
					}
				}
			}
			return $result;
		}
        function PreProcess($page)
		{
			//session_start();
			date_default_timezone_set("Etc/Universal");
			
			$User = User::CheckLogin();
			$User->CheckAccessRights(get_class($this), true);

			$Api = new ApiInterface($User->GetAccountId());
			$Api->userId = $User->GetUserId();
			$Api->apiKey = $User->GetApiKey();
			$Api->characterId = $User->GetCharacterId();
			$corpInfo = $Api->GetCorpCorporationSheet();
			$accountId = $User->GetAccountId();

			//проверка ввода первой даты
			if(isset($_POST["date1"]))
			{
				$date1Str = $this->ParseDate($_POST["date1"], date("Y-m-d", strtotime("-1 day")));
			}
			else
			{
				//если сохранено в сессии, берэм оттуда
				if(isset($_SESSION["date1"]))
					$date1Str = $_SESSION["date1"];
				else
					$date1Str = date("Y-m-d", strtotime("-1 day"));
			}
			$_SESSION["date1"] = $date1Str;

			//проверка ввода второй даты
			if(isset($_POST["date2"]))
			{
				$date2Str = $this->ParseDate($_POST["date2"], date("Y-m-d"));
			}
			else
			{
				//если сохранено в сессии, берэм оттуда
				if(isset($_SESSION["date2"]))
					$date2Str = $_SESSION["date2"];
				else
					$date2Str = date("Y-m-d");
			}
			$_SESSION["date2"] = $date2Str;

			//проверка ввода первой суммы
			if(isset($_POST["summ1"]))
			{
				$summ1 = $_POST["summ1"];
				if(preg_match("/^-?\d+?$/", $summ1) == 0)
					$summ1 = "-100000000";
			}
			else
			{
				if(isset($_SESSION["summ1"]))
					$summ1 = $_SESSION["summ1"];
				else
					$summ1 = "-100000000";
			}
			$_SESSION["summ1"] = $summ1;

			//проверка ввода второй суммы
			if(isset($_POST["summ2"]))
			{
				$summ2 = $_POST["summ2"];
				if(preg_match("/^-?\d+?$/", $summ2) == 0)
					$summ2 = "100000000";
			}
			else
			{
				if(isset($_SESSION["summ2"]))
					$summ2 = $_SESSION["summ2"];
				else
					$summ2 = "100000000";
			}
			$_SESSION["summ2"] = $summ2;

			$request_processor = $_SERVER["PHP_SELF"] . "?mode=" . get_class($this);

			//сбор выбранных в форме значений divisions
			$selectedDivisions = array();
			if (isset($_POST["divisions"]))
			{
				$divisions=$_POST["divisions"];
				//print_r($divisions);
				foreach ($divisions as $division)
				{
					array_push($selectedDivisions, $division);
				}
			}
			else
			{
				if(isset($_SESSION["selectedDivisions"]))
					$selectedDivisions = $_SESSION["selectedDivisions"];
			}
			$_SESSION["selectedDivisions"] = $selectedDivisions;
			//print_r($selectedDivisions);


			$divisions = $corpInfo["walletDivisions"];


			$refTypes = array();
			$db = OpenDB2();
			$qr = $db->query("select * from api_reftypes order by refTypeName;");
			while($row = $qr->fetch_assoc())
			{
				$refTypes[$row["refTypeId"]] = $row["refTypeName"];
			}
			$qr->close();


			$selDivs = "";
			foreach ($selectedDivisions as $k=>$v)
			{
				if($selDivs != "")
					$selDivs .= ",";
				$selDivs .= $v;
			}
			//построение выражения для where
			$where = " where accountId = '$accountId' and (price*quantity between $summ1 and $summ2) and (_date_ between '$date1Str' and '$date2Str')";
			if($selDivs != "")
				$where .= " and accountKey in ($selDivs)";

			//подсчёт числа подходящих строк
			$qr = $db->query("select count(*) as _count_ from api_wallet_transactions $where;");
			$row = $qr->fetch_assoc();
			$recordsCount = $row["_count_"];
			$qr->close();


			//$page->Body .= $query;

			$page->Body = "
<form action='$request_processor' method='post'>
	<label for='date1'>Дата:</label>
	<input type='text' id='date1' name='date1' value='$date1Str' size='10' maxlength='10'>
	<label for='date2'>-</label>
	<input type='text' id='date2' name='date2' value='$date2Str' size='10' maxlength='10'>
	<label for='summ1'>Сумма:</label>
	<input type='text' id='summ1' name='summ1' value='$summ1' size='10' maxlength='15'>
	<label for='summ2'>-</label>
	<input type='text' id='summ2' name='summ2' value='$summ2' size='10' maxlength='15'>
	<br>
<SELECT multiple name='divisions[]'>";
			foreach ($divisions as $k=>$v)
			{
				if(in_array($k, $selectedDivisions))
					$sel = "selected";
				else
					$sel = "";
				$k = $k;
				$v = $v;
				$page->Body .= "<option value='$k' $sel>[$k] $v</option>";
			}
			$page->Body .= "
</SELECT>
<input type='submit' value='OK'>
</form>
";
			$pages = new PageSelector();
			$page->Body .= $pages->Write($recordsCount);
			$page->Body .= "
<table class='b-border b-widthfull'>
    <tr class='b-table-caption'>
		<td>#</td>\n";


			$page->Body .= $page->WriteSorter(array (
				"accountKey" => "Кошелёк",
				"transId" => "Ref Id",
				"_date_" => "Дата",
				"typeName" => "Товар",
				"quantity" => "Количество",
				"price" => "Цена",
				"amount" => "Сумма",
				"characterName" => "Character",
				"clientName" => "Client",
				"stationName" => "Станция",
				"transactionType" => "Тип"
				));
			$page->Body .= "
    </tr>";
			$sorter = $page->GetSorter("_date_");
			//отдельно количество можно не запрашивать, т.к. есть свойство affected_rows
			//нет. оказалось надо отдельно, т.к. число записей нужно для определения покаызываемой страницы
			$query = 
"select *, price*quantity as amount from api_wallet_transactions\n
$where $sorter limit $pages->start, $pages->count;";
			$qr = $db->query($query);


    		$rowIndex = $pages->start;
			while($row = $qr->fetch_assoc())
			{
				if(($rowIndex % 2) == 1)
					$rowClass = "b-row-even";
				else
					$rowClass = "b-row-odd";

				$walletName = $divisions[$row["accountKey"]];
				$price  = $page->FormatNum($row["price"], 2);
				$amount = $page->FormatNum($row["amount"], 2);
				$page->Body .= "
	<tr class='$rowClass'>
		<td>$rowIndex</td>
		<td>[$row[accountKey]]</td>
		<td>$row[transId]</td>
		<td>$row[_date_]</td>
		<td>$row[typeName]</td>
		<td class='b-right'>$row[quantity]</td>
		<td class='b-right'>$price</td>
		<td class='b-right'>$amount</td>
		<td>$row[characterName]</td>
		<td>$row[clientName]</td>
		<td>$row[stationName]</td>
		<td>$row[transactionType]</td>
	</tr>
";
				$rowIndex++;
			}
    		$page->Body .= "
</table>
";
			$page->Body .= $pages->Write($recordsCount);
			$qr->close();
			$db->close();

		}
	}
?>
