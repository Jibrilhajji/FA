<?php
/**********************************************************************
    Copyright (C) FrontAccounting, LLC.
	Released under the terms of the GNU General Public License, GPL, 
	as published by the Free Software Foundation, either version 3 
	of the License, or (at your option) any later version.
    This program is distributed in the hope that it will be useful,
    but WITHOUT ANY WARRANTY; without even the implied warranty of
    MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  
    See the License here <http://www.gnu.org/licenses/gpl-3.0.html>.
***********************************************************************/
$page_security = 2;
// ----------------------------------------------------------------
// $ Revision:	2.0 $
// Creator:	Joe Hunt
// date_:	2005-05-19
// Title:	Supplier Balances
// ----------------------------------------------------------------
$path_to_root="..";

include_once($path_to_root . "/includes/session.inc");
include_once($path_to_root . "/includes/date_functions.inc");
include_once($path_to_root . "/includes/data_checks.inc");
include_once($path_to_root . "/gl/includes/gl_db.inc");

//----------------------------------------------------------------------------------------------------

print_supplier_balances();

function get_open_balance($supplier_id, $to, $convert)
{
	$to = date2sql($to);

    $sql = "SELECT SUM(IF(".TB_PREF."supp_trans.type = 20, (".TB_PREF."supp_trans.ov_amount + ".TB_PREF."supp_trans.ov_gst + 
    	".TB_PREF."supp_trans.ov_discount)";
    if ($convert)
    	$sql .= " * rate";
    $sql .= ", 0)) AS charges,
    	SUM(IF(".TB_PREF."supp_trans.type <> 20, (".TB_PREF."supp_trans.ov_amount + ".TB_PREF."supp_trans.ov_gst + 
    	".TB_PREF."supp_trans.ov_discount)";
    if ($convert)
    	$sql .= "* rate";
    $sql .= ", 0)) AS credits,
		SUM(".TB_PREF."supp_trans.alloc";
	if ($convert)
		$sql .= " * rate";
	$sql .= ") AS Allocated,
		SUM((".TB_PREF."supp_trans.ov_amount + ".TB_PREF."supp_trans.ov_gst + 
    	".TB_PREF."supp_trans.ov_discount - ".TB_PREF."supp_trans.alloc)";
    if ($convert)
    	$sql .= " * rate";
    $sql .= ") AS OutStanding
		FROM ".TB_PREF."supp_trans
    	WHERE ".TB_PREF."supp_trans.tran_date < '$to'
		AND ".TB_PREF."supp_trans.supplier_id = '$supplier_id' GROUP BY supplier_id";

    $result = db_query($sql,"No transactions were returned");
    return db_fetch($result);
}

function getTransactions($supplier_id, $from, $to)
{
	$from = date2sql($from);
	$to = date2sql($to);

    $sql = "SELECT ".TB_PREF."supp_trans.*,
				(".TB_PREF."supp_trans.ov_amount + ".TB_PREF."supp_trans.ov_gst + ".TB_PREF."supp_trans.ov_discount)
				AS TotalAmount, ".TB_PREF."supp_trans.alloc AS Allocated,
				((".TB_PREF."supp_trans.type = 20)
					AND ".TB_PREF."supp_trans.due_date < '$to') AS OverDue
    			FROM ".TB_PREF."supp_trans
    			WHERE ".TB_PREF."supp_trans.tran_date >= '$from' AND ".TB_PREF."supp_trans.tran_date <= '$to' 
    			AND ".TB_PREF."supp_trans.supplier_id = '$supplier_id'
    				ORDER BY ".TB_PREF."supp_trans.tran_date";

    $TransResult = db_query($sql,"No transactions were returned");

    return $TransResult;
}

//----------------------------------------------------------------------------------------------------

function print_supplier_balances()
{
    global $path_to_root;

    $from = $_POST['PARAM_0'];
    $to = $_POST['PARAM_1'];
    $fromsupp = $_POST['PARAM_2'];
    $currency = $_POST['PARAM_3'];
    $comments = $_POST['PARAM_4'];
	$destination = $_POST['PARAM_5'];
	if ($destination)
		include_once($path_to_root . "/reporting/includes/excel_report.inc");
	else
		include_once($path_to_root . "/reporting/includes/pdf_report.inc");

	if ($fromsupp == reserved_words::get_all_numeric())
		$supp = _('All');
	else
		$supp = get_supplier_name($fromsupp);
    $dec = user_price_dec();

	if ($currency == reserved_words::get_all())
	{
		$convert = true;
		$currency = _('Balances in Home currency');
	}
	else
		$convert = false;

	$cols = array(0, 100, 130, 190,	250, 320, 385, 450,	515);

	$headers = array(_('Trans Type'), _('#'), _('Date'), _('Due Date'), _('Charges'),
		_('Credits'), _('Allocated'), _('Outstanding'));

	$aligns = array('left',	'left',	'left',	'left',	'right', 'right', 'right', 'right');

    $params =   array( 	0 => $comments,
    				    1 => array('text' => _('Period'), 'from' => $from, 'to' => $to),
    				    2 => array('text' => _('Supplier'), 'from' => $supp, 'to' => ''),
    				    3 => array(  'text' => _('Currency'),'from' => $currency, 'to' => ''));

    $rep = new FrontReport(_('Supplier Balances'), "SupplierBalances", user_pagesize());

    $rep->Font();
    $rep->Info($params, $cols, $headers, $aligns);
    $rep->Header();

	$total = array();
	$grandtotal = array(0,0,0,0);

	$sql = "SELECT supplier_id, supp_name AS name, curr_code FROM ".TB_PREF."suppliers ";
	if ($fromsupp != reserved_words::get_all_numeric())
		$sql .= "WHERE supplier_id=$fromsupp ";
	$sql .= "ORDER BY supp_name";
	$result = db_query($sql, "The customers could not be retrieved");

	while ($myrow=db_fetch($result))
	{
		if (!$convert && $currency != $myrow['curr_code'])
			continue;
		$rep->fontSize += 2;
		$rep->TextCol(0, 2, $myrow['name']);
		if ($convert)
			$rep->TextCol(2, 3,	$myrow['curr_code']);
		$rep->fontSize -= 2;
		$bal = get_open_balance($myrow['supplier_id'], $from, $convert);
		$init[0] = $init[1] = 0.0;
		$rep->TextCol(3, 4,	_("Open Balance"));
		$init[0] = round2(abs($bal['charges']), $dec);
		$rep->AmountCol(4, 5, $init[0], $dec);
		$init[1] = round2(Abs($bal['credits']), $dec);
		$rep->AmountCol(5, 6, $init[1], $dec);
		$init[2] = round2($bal['Allocated'], $dec);
		$rep->AmountCol(6, 7, $init[2], $dec);
		$init[3] = round2($bal['OutStanding'], $dec);;
		$rep->AmountCol(7, 8, $init[3], $dec);
		$total = array(0,0,0,0);
		for ($i = 0; $i < 4; $i++)
		{
			$total[$i] += $init[$i];
			$grandtotal[$i] += $init[$i];
		}
		$rep->NewLine(1, 2);
		$res = getTransactions($myrow['supplier_id'], $from, $to);
		if (db_num_rows($res)==0)
			continue;
		$rep->Line($rep->row + 4);
		while ($trans=db_fetch($res))
		{
			$rep->NewLine(1, 2);
			$rep->TextCol(0, 1,	systypes::name($trans['type']));
			$rep->TextCol(1, 2,	$trans['reference']);
			$rep->DateCol(2, 3,	$trans['tran_date'], true);
			if ($trans['type'] == 20)
				$rep->DateCol(3, 4,	$trans['due_date'], true);
			$item[0] = $item[1] = 0.0;
			if ($convert)
				$rate = $trans['rate'];
			else
				$rate = 1.0;
			if ($trans['TotalAmount'] > 0.0)
			{
				$item[0] = round2(abs($trans['TotalAmount']) * $rate, $dec);
				$rep->AmountCol(4, 5, $item[0], $dec);
			}
			else
			{
				$item[1] = round2(abs($trans['TotalAmount']) * $rate, $dec);
				$rep->AmountCol(5, 6, $item[1], $dec);
			}
			$item[2] = round2($trans['Allocated'] * $rate, $dec);
			$rep->AmountCol(6, 7, $item[2], $dec);
			/*
			if ($trans['type'] == 20)
				$item[3] = ($trans['TotalAmount'] - $trans['Allocated']) * $rate;
			else
				$item[3] = ($trans['TotalAmount'] + $trans['Allocated']) * $rate;
			*/	
			if ($trans['type'] == 20)
				$item[3] = $item[0] + $item[1] - $item[2];
			else	
				$item[3] = $item[0] - $item[1] + $item[2];
			$rep->AmountCol(7, 8, $item[3], $dec);
			for ($i = 0; $i < 4; $i++)
			{
				$total[$i] += $item[$i];
				$grandtotal[$i] += $item[$i];
			}
		}
		$rep->Line($rep->row - 8);
		$rep->NewLine(2);
		$rep->TextCol(0, 3,	_('Total'));
		for ($i = 0; $i < 4; $i++)
		{
			$rep->AmountCol($i + 4, $i + 5, $total[$i], $dec);
			$total[$i] = 0.0;
		}
    	$rep->Line($rep->row  - 4);
    	$rep->NewLine(2);
	}
	$rep->fontSize += 2;
	$rep->TextCol(0, 3,	_('Grand Total'));
	$rep->fontSize -= 2;
	for ($i = 0; $i < 4; $i++)
		$rep->AmountCol($i + 4, $i + 5,$grandtotal[$i], $dec);
	$rep->Line($rep->row  - 4);
	$rep->NewLine();
    $rep->End();
}

?>