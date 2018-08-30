<?php

// Modul pro komunikaci s ISDS
//
// Verze 2.0
//
// (c) 2009 Software602 a.s.
//
// Pripadne dotazy a pripominky zasilejte na isds@602.cz

// veskere stringy jsou ocekavany v kodovani UTF-8

// vyzaduje PHP 5 >= 5.0.1

// v php.ini je treba mit povolene nasledujici extensions: php_curl.dll, php_openssl.dll, php_soap.dll


require_once('ConfigISDS.php');	

//echo "<br>portaltype=$portaltype<br>";

function QX($s)
// debugovaci funkce                            
{
	global $debug;

	if ($debug == 0)
	{
		return;
	}

	echo($s."\r\n");
}

function QY($s)
// debugovaci funkce
{
	echo($s."\r\n");
}


function QV($s)
// debugovaci funkce
{
	global $debug;

	if ($debug == 0)
	{
		return;
	}
	var_dump($s);
}

// service type
$OperationsService = 0;
$InfoService = 1;
$ManipulationsService = 2;
$AccessService=3;

function GetServiceURL($ServiceType,$LoginType)
{
	global $portaltype;
  //$portaltype=0; // cvicna
  //$portaltype=1;  //ostra

	$res = "https://ws1";
	if ($LoginType > 0)
	{
		$res = $res."c";
	}
	switch ($portaltype):
	case 0:
		$res = $res.".czebox.cz/";
		break;
	case 1:
		$res = $res.".mojedatovaschranka.cz/";
    //echo "<br>jaky provoz: $res<br>";
		break;
	endswitch;
	switch ($LoginType):
	case 1:
		$res = $res."cert/";
		break;
	case 2:
		$res = $res."hspis/";
		break;
	endswitch;
	$res = $res."DS/";
	switch ($ServiceType):
	case 0:
		$res = $res."dz";
		break;
	case 1:
		$res = $res."dx";
		break;
	case 2:
		$res = $res."DsManage";
		break;
	case 3:
		$res = $res."DsManage";
		break;
	endswitch;	
    //echo "<br>jaky provoz: $res<br>";
	return $res;
}

function GetServiceWSDL($ServiceType)
{
	switch ($ServiceType):
	case 0:
		return "dm_operations.wsdl";
	case 1:
		return "dm_info.wsdl";
	case 2:
		return "db_manipulations.wsdl";
	case 3:
		return "db_access.wsdl";
	endswitch;				
}

class ISDSSentOutFiles
// Trida pouzita pro pridavani souboru do odesilane zpravy
{
	var $FileInfos = array();
	var $FullFileNames = array();

	function ISDSSentOutFiles()
	{
	}

	function AddFileSpecFromMemory(
	// Prida soubor
        	$FullFileName,	// Uplne jmeno souboru (vcetne cesty)
        	$file,		// bytove pole obsahujici soubor
		$MimeType,	// Typ souboru v MIME zapisu, napr. application/pdf nebo image/tiff
		$MetaType,	// Typ pisemnosti
		$Guid,		// Nepovinny interni identifikator tohoto dokumentu - pro vytvareni stromu zavislosti dokumentu
		$UpFileGuid,	// Nepovinny interni identifikator nadrizeneho dokumentu (napr. pro vztah soubor - podpis aj.)
		$FileDescr,	// Muze obsahovat jmeno souboru, prip. jiny popis. Objevi se v seznamu priloh na portale
		$Format)	// Nepovinny udaj - odkaz na definici formulare
	{
		QX("MimeType: ".$MimeType);
		QX("MetaType: ".$MetaType);
		QX("Guid: ".$Guid);
		QX("UpFileGuid: ".$UpFileGuid);
		QX("FileDescr: ".$FileDescr);
		QX("Format: ".$Format);
		$dmFile=array(
			'dmMimeType'=>$MimeType,
			'dmFileMetaType'=>$MetaType,
			'dmFileGuid'=>$Guid,
			'dmUpFileGuid'=>$UpFileGuid,
			'dmFileDescr'=>$FileDescr,
			'dmFormat'=>$Format,
			'dmEncodedContent'=>$file
			);
		if ($this->FileInfos == null)
		{
			$this->FileInfos[0] = $dmFile;
			$this->FullFileNames[0] = $FullFileName;
		}
		else
		{
			$this->FileInfos[count($this->FileInfos)]=$dmFile;
			$this->FullFileNames[count($this->FullFileNames)]=$FullFileName;
		}		
		QV($this->FullFileNames);
		return true;
	}

	function AddFileSpecFromFile(
	// Prida soubor
        	$FullFileName,	// Uplne jmeno souboru (vcetne cesty)
		$MimeType,	// Typ souboru v MIME zapisu, napr. application/pdf nebo image/tiff
		$MetaType,	// Typ pisemnosti
		$Guid,		// Nepovinny interni identifikator tohoto dokumentu - pro vytvareni stromu zavislosti dokumentu
		$UpFileGuid,	// Nepovinny interni identifikator nadrizeneho dokumentu (napr. pro vztah soubor - podpis aj.)
		$FileDescr,	// Muze obsahovat jmeno souboru, prip. jiny popis. Objevi se v seznamu priloh na portale
		$Format)	// Nepovinny udaj - odkaz na definici formulare
	{
		QX("Pridavan soubor: ".$FullFileName);
		if (!file_exists($FullFileName))
		{
			QX("Soubor nenalezen");
			return false;
		}
		$file=file_get_contents($FullFileName);
		if (!$file)
		{
			QX("Soubor se nepodarilo nacist");
			return false;
		}
		return $this->AddFileSpecFromMemory($FullFileName,$file,$MimeType,$MetaType,$Guid,$UpFileGuid,$FileDescr,$Format);
	}

	function AddFile($FullFileName,$MimeType)
	// zjednodusena funkce pro pridani souboru
	{
		if ($this->FileInfos == null)
		{
			$MetaType="main";
		}
		else
		{
			$MetaType="enclosure";
		}		
		$path_parts = pathinfo($FullFileName);		
		return $this->AddFileSpecFromFile($FullFileName,
			$MimeType,
			$MetaType,"","",$path_parts['basename'],"");
	}

}

class ISDSSoapClient extends SoapClient 
{
	var $ch;

	function __doRequest($request, $location, $action, $version) 
	{
		global $debug,$proxyaddress,$proxyport,$proxylogin,$proxypwd,$onlycurl,$usenss;

		if ($debug > 1)
		{
			QX('#######################################################################');
			QX('__doRequest:');
			QX('certfilename: '.$this->certfilename);
			QX('passphrase: '.$this->passphrase);
			QX('Request: '.$request);
			QX('Location: '.$location);
			QX('Action: '.$action);
			QX('Version: '.$version);
		}

		QX('Location: '.$location);

		$headers = array(
			'Method: POST',
			'Connection: Keep-Alive',
			'User-Agent: 602',
			'Content-Type: text/xml; charset=utf-8',
			'SOAPAction: "'.$action.'"'
			);  
		$this->__last_request_headers = $headers;
		
		curl_setopt($this->ch, CURLOPT_URL, $location);
		curl_setopt($this->ch, CURLOPT_HTTPHEADER, $headers);
		curl_setopt($this->ch, CURLOPT_POSTFIELDS,$request);

		$response = curl_exec($this->ch); 
			
		if (curl_errno($this->ch) != 0)
		{
			throw new exception('CurlError: '.curl_errno($this->ch).' Message:'.curl_error($this->ch));
		}
			
		QX("------------------------------------------------------------------------");
		QX('CurlError: '.curl_errno($this->ch).' Message:'.curl_error($this->ch));
		QX("------------------------------------------------------------------------");
		QV($response);
		QX("------------------------------------------------------------------------");
		return $response;
	}

}

// trida pro komunikaci s ISDS schrankou
class ISDSBox
{
	
	// sluzby pro pristup ke schrance
	var $OperationsWS;
	var $InfoWS;	
	var $ManipulationsWS;
	var $AccessWS;
	var $StatusCode;	// statuscode posledni akce
	var $StatusMessage;	// statusmessage posledni akce
	var $ErrorInfo;		// popis chyby vznikle pri volani sluzby posledni akce
	var $ValidLogin;	// true pokud probehlo uspesne prihlaseni
	var $ch;			// curl handle

	// konstruktor
	function ISDSBox(
		$logintype,	// zpusob prihlaseni. 0=jmeno heslo, 1=spisovka (certifikat), 2=hostovana spisovka (jmeno, heslo, certifikat)
		$loginname,	// prihlasovaci jmeno
		$password,	// prihlasovaci heslo
		$certfilename, 	// cesta k certifikatu
		$passphrase)	// heslo k soukromemu klici v certifikatu	
	{
		global $proxyaddress,$proxyport,$proxylogin,$proxypwd,$validlogin;

		QX("Constructor");
		$this->ch=0;
		$this->ValidLogin=false;
		$this->NullRetInfo();

		// "prebytecne" udaje vynulujeme
		if ($logintype == 0)
		{
			$certfilename = "";
			$passphrase = "";
		}
		else
		{
			if ($logintype == 1)
			{
				$loginname = "";
				$password = "";
			}
		}

		QX("LoginType: ".$logintype);
		
		try
		{
			$this->InitCurl($logintype,$loginname,$password,$certfilename,$passphrase);
		}
		catch (Exception $e)
		{
			$this->ErrorInfo=$e->getMessage();
			return false;
		}
		
		$this->OperationsWS = new ISDSSoapClient(GetServiceWSDL(0),array(
			'login'=>$loginname,
			'password'=>$password,
			'proxy_host'=>$proxyaddress,
			'proxy_port'=>$proxyport,
			'proxy_login'=>$proxylogin,
			'proxy_password'=>$proxypwd,
			'location'=>GetServiceURL(0,$logintype),
			'trace'=>true,
			'exceptions'=>true));
		$this->OperationsWS->ch = $this->ch;
		$this->InfoWS = new ISDSSoapClient(GetServiceWSDL(1),array(
			'login'=>$loginname,
			'password'=>$password,
			'proxy_host'=>$proxyaddress,
			'proxy_port'=>$proxyport,
			'proxy_login'=>$proxylogin,
			'proxy_password'=>$proxypwd,
			'location'=>GetServiceURL(1,$logintype),
			'trace'=>true,
			'exceptions'=>true));
		$this->InfoWS->ch = $this->ch;
		$this->ManipulationsWS = new ISDSSoapClient(GetServiceWSDL(2),array(
			'login'=>$loginname,
			'password'=>$password,
			'proxy_host'=>$proxyaddress,
			'proxy_port'=>$proxyport,
			'proxy_login'=>$proxylogin,
			'proxy_password'=>$proxypwd,
			'location'=>GetServiceURL(2,$logintype),
			'trace'=>true,
			'exceptions'=>true));
		$this->ManipulationsWS->ch = $this->ch;
		$this->AccessWS = new ISDSSoapClient(GetServiceWSDL(3),array(
			'login'=>$loginname,
			'password'=>$password,
			'proxy_host'=>$proxyaddress,
			'proxy_port'=>$proxyport,
			'proxy_login'=>$proxylogin,
			'proxy_password'=>$proxypwd,
			'location'=>GetServiceURL(3,$logintype),
			'trace'=>true,
			'exceptions'=>true));
		$this->AccessWS->ch = $this->ch;
		$this->ValidLogin = true;
		return true;
	}

	function InitCurl(
		$logintype,	// zpusob prihlaseni. 0=jmeno heslo, 1=spisovka (certifikat), 2=hostovana spisovka (jmeno, heslo, certifikat)
		$loginname,	// prihlasovaci jmeno
		$password,	// prihlasovaci heslo
		$certfilename, 	// cesta k certifikatu
		$passphrase)	// heslo k soukromemu klici v certifikatu	
	{
		global $debug,$proxyaddress,$proxyport,$proxylogin,$proxypwd;
		
		$this->ch = curl_init();
		curl_setopt($this->ch, CURLOPT_POST, true);
		curl_setopt($this->ch, CURLOPT_RETURNTRANSFER, true);
		curl_setopt($this->ch, CURLOPT_FAILONERROR, true);
		curl_setopt($this->ch, CURLOPT_FOLLOWLOCATION, true);
		curl_setopt($this->ch, CURLINFO_HEADER_OUT, true);
		curl_setopt($this->ch, CURLOPT_UNRESTRICTED_AUTH,false);
		curl_setopt($this->ch, CURLOPT_NOBODY,false);
		if ($loginname != "")
		{
			curl_setopt($this->ch, CURLOPT_USERPWD,$loginname.":".$password);
		}
		// na Linuxu nastavit verzi 3, na Windows ne !
		if (stristr(PHP_OS,'WIN') === false)
		{
			curl_setopt($this->ch, CURLOPT_SSLVERSION,3);
		}
		if ($logintype != 0)
		{				
			curl_setopt($this->ch, CURLOPT_SSLCERT,$certfilename);
			curl_setopt($this->ch, CURLOPT_SSLCERTPASSWD,$passphrase);				
		}
		curl_setopt($this->ch, CURLOPT_SSL_VERIFYPEER, false); // kasle se na https certy  
		if ($proxylogin != '')
		{
			QX('######### PRISTUP PRES PROXY ############ '.$proxyaddress.':'.$proxyport);
			curl_setopt($this->ch, CURLOPT_PROXY, 'http://'.$proxyaddress.':'.$proxyport);
			curl_setopt($this->ch, CURLOPT_HTTPPROXYTUNNEL, true);				
			if ($proxyauth != '')
			{
				curl_setopt($this->ch, CURLOPT_PROXYUSERPWD, $proxylogin.':'.$proxypwd);
			}
		}		
		
		curl_setopt($this->ch, CURLOPT_URL, GetServiceURL(0,$logintype));
		curl_setopt($this->ch, CURLOPT_POSTFIELDS,
			"<?xml version=\"1.0\" encoding=\"utf-8\"?>\n".
			"    <soap:Envelope xmlns:soap=\"http://schemas.xmlsoap.org/soap/envelope/\" xmlns:xsi=\"http://www.w3.org/2001/XMLSchema-instance\" xmlns:xsd=\"http://www.w3.org/2001/XMLSchema\">\n".
			"  <soap:Body>\n".
			"    <DummyOperation xmlns=\"http://isds.czechpoint.cz\">\n".
			"    </DummyOperation>\n".
			"  </soap:Body>\n".
			"</soap:Envelope>\n");

		QX("Call curl_exec");

		if ($logintype != 0)
		{
			curl_setopt($this->ch, CURLOPT_SSLCERT,$certfilename);
			curl_setopt($this->ch, CURLOPT_SSLCERTPASSWD,$passphrase);				
		}
	}

	function __destruct() 
	{		
		QX('volan destructor');
		
		global $portaltype;
		
		if ($this->ch != 0)
		{
			try
			{
				curl_close($this->ch);           
			}
			catch (Exception $e)
			{
			}
						
		}
	}

	function NullRetInfo()
	// pomocna funkce
	{
		$this->ErrorInfo="";
		$this->StatusCode="";
		$this->StatusMessage="";
	}


	function CreateMessage(
	// Vytvori datovou zpravu a umisti ji do dane schranky. Vrati identifikaci vytvorene zpravy.
		$IDRecipient,		// Identifikace adresata
		$Annotation,		// Textova poznamka (vec, predmet, anotace)
		$AllowSubstDelivery,	// Nahradni doruceni povoleno/nepovoleno - pouze pro nektere subjekty (napr. soudy)
		$LegalTitleLaw,		// Zmocneni - cislo zakona
		$LegalTitlePar,		// Zmocneni - odstavec v paragrafu
		$LegalTitlePoint,	// Zmocneni - pismeno v odstavci
		$LegalTitleSect,	// Zmocneni - paragraf v zakone
		$LegalTitleYear,	// Zmocneni - rok vydani zakona
		$RecipientIdent,	// Spisova znacka ze strany prijemce, Nepovinne.
		$RecipientOrgUnit,	// Organizacni jednotka prijemce slovne, nepovinne, mozne upresneni prijemce pri podani z portalu
		$RecipientOrgUnitNum,	// Organizacni jednotka prijemce hodnotou z ciselniku, nepovinne, pokud nechcete urcit zadejte -1
		$RecipientRefNumber,	// Cislo jednaci ze strany prijemce, nepovinne
		$SenderIdent,		// Spisova znacka ze strany odesilatele
		$SenderOrgUnit,		// Organizacni jednotka odesilatele slovne. Nepovinne
		$SenderOrgUnitNum,	// Organizacni jednotka odesilatele hodnotou z ciselniku. Nepovinne.
		$SenderRefNumber,	// Cislo jednaci ze strany odesilatele. Nepovinne
		$ToHands,		// Popis komu je zasilka urcena
		$PersonalDelivery,	// Priznak "Do vlastnich rukou" znacici, ze zpravu muze cist pouze adresat nebo osoba s explicitne danym opravnenim
		$OVM,			// Priznak je-li DS odesilana v rezimu OVM
		$OutFiles)		// Pripojene soubory (pisemnosti)
	{
		QX("Call CreateMessage");
		$this->NullRetInfo();
		$Envelope = array(
			'dmSenderOrgUnit' => $SenderOrgUnit,
			'dmSenderOrgUnitNum' => $SenderOrgUnitNum,
			'dbIDRecipient' => $IDRecipient,
			'dmRecipientOrgUnit' => $RecipientOrgUnit,
			'dmRecipientOrgUnitNum' => $RecipientOrgUnitNum,
			'dmToHands' => $ToHands,
			'dmAnnotation' => $Annotation,
			'dmRecipientRefNumber' => $RecipientRefNumber,
			'dmSenderRefNumber' => $SenderRefNumber,
			'dmRecipientIdent' => $RecipientIdent,
			'dmSenderIdent' => $SenderIdent,
			'dmLegalTitleLaw' => $LegalTitleLaw,
			'dmLegalTitleYear' => $LegalTitleYear,
			'dmLegalTitleSect' => $LegalTitleSect,
			'dmLegalTitlePar' => $LegalTitlePar,
			'dmLegalTitlePoint' => $LegalTitlePoint,
			'dmPersonalDelivery' => $PersonalDelivery,
			'dmAllowSubstDelivery' => $AllowSubstDelivery,
			'dmOVM'=>$OVM);
		$MessageCreateInput = array(
			'dmEnvelope' => $Envelope,
			'dmFiles' => $OutFiles->FileInfos);	
		try
		{
			$MessageCreateOutput=$this->OperationsWS->CreateMessage($MessageCreateInput);
		}
		catch (Exception $e)
		{
			$this->ErrorInfo=$e->getMessage();
			return false;
		}
		$MessageID=$MessageCreateOutput->dmID;
		$MessageStatus=$MessageCreateOutput->dmStatus;
		$this->StatusCode=$MessageStatus->dmStatusCode;
		$this->StatusMessage=$MessageStatus->dmStatusMessage;
		QX("MessageID: ".$MessageID);
		QX("StatusCode: ".$this->StatusCode);
		QX("StatusMessage: ".$this->StatusMessage);
		return $MessageID;
	}

	// vytvori hromadnou datovou zpravu a umisti ji do danych schranek
	// vraci pole informujici o tom jak dopadlo odeslani zasilky jednotlivym adresatum
	// Jednotlive prvky vraceneho pole obsahuji tyto polozky:
	// dmID = identifikace vytvorene zpravy
	// dmStatus->dmStatusCode
	// dmStatus->dmStatusMessage	
	function CreateMultipleMessage(
		$Recipients,		// adresati zasilky. Pole poli obsahujici nasledujici polozky:
					// dbIDRecipient = ID adresata
					// dmRecipientOrgUnit = Organizacni jednotka adresata slovne
					// dmRecipientOrgUnitNum = Cislo organizacni jednotky adresata
					// dmToHands = popis komu je zasilka urcena
					// Pole adresatu muze obsahovat maximalne 50 prvku
		$Annotation,		// Textova poznamka (vec, predmet, anotace)
		$AllowSubstDelivery,	// Nahradni doruceni povoleno/nepovoleno - pouze pro nektere subjekty (napr. soudy)
		$LegalTitleLaw,		// Zmocneni - cislo zakona
		$LegalTitlePar,		// Zmocneni - odstavec v paragrafu
		$LegalTitlePoint,	// Zmocneni - pismeno v odstavci
		$LegalTitleSect,	// Zmocneni - paragraf v zakone
		$LegalTitleYear,	// Zmocneni - rok vydani zakona
		$RecipientIdent,	// Spisova znacka ze strany prijemce, Nepovinne.
		$RecipientRefNumber,	// Cislo jednaci ze strany prijemce, nepovinne
		$SenderIdent,		// Spisova znacka ze strany odesilatele
		$SenderOrgUnit,		// Organizacni jednotka odesilatele slovne. Nepovinne
		$SenderOrgUnitNum,	// Organizacni jednotka odesilatele hodnotou z ciselniku. Nepovinne.
		$SenderRefNumber,	// Cislo jednaci ze strany odesilatele. Nepovinne
		$PersonalDelivery,	// Priznak "Do vlastních rukou" znacici, ze zpravu muze cist pouze adresat nebo osoba s explicitne danym opravnenim
		$OVM,			// Priznak je-li DS odesilana v rezimu OVM
		$OutFiles)		// Pripojene soubory (pisemnosti)
	{
		QX("Call CreateMultipleMessage");
		$this->NullRetInfo();
		$Envelope = array(
			'dmSenderOrgUnit' => $SenderOrgUnit,
			'dmSenderOrgUnitNum' => $SenderOrgUnitNum,
			'dmAnnotation' => $Annotation,
			'dmRecipientRefNumber' => $RecipientRefNumber,
			'dmSenderRefNumber' => $SenderRefNumber,
			'dmRecipientIdent' => $RecipientIdent,
			'dmSenderIdent' => $SenderIdent,
			'dmLegalTitleLaw' => $LegalTitleLaw,
			'dmLegalTitleYear' => $LegalTitleYear,
			'dmLegalTitleSect' => $LegalTitleSect,
			'dmLegalTitlePar' => $LegalTitlePar,
			'dmLegalTitlePoint' => $LegalTitlePoint,
			'dmPersonalDelivery' => $PersonalDelivery,
			'dmAllowSubstDelivery' => $AllowSubstDelivery,
			'dmOVM'=>$OVM);
		$MultipleMessageCreateInput = array(
			'dmRecipients' => $Recipients,
			'dmEnvelope' => $Envelope,
			'dmFiles' => $OutFiles->FileInfos);
		try
		{
			$MultipleMessageCreateOutput=$this->OperationsWS->CreateMultipleMessage($MultipleMessageCreateInput);
		}
		catch (Exception $e)
		{
			$this->ErrorInfo=$e->getMessage();
			return false;
		}
		$MessageStatus=$MultipleMessageCreateOutput->dmStatus;
		$this->StatusCode=$MessageStatus->dmStatusCode;
		$this->StatusMessage=$MessageStatus->dmStatusMessage;
		QX("StatusCode: ".$this->StatusCode);
		QX("StatusMessage: ".$this->StatusMessage);
		return $this->PrepareArray($MultipleMessageCreateOutput->dmMultipleStatus->dmSingleStatus);		
	}

	function MessageDownload(
	// Stazeni prijate zpravy z ISDS bez elektronickeho podpisu. Vrati stazenou zpravu.
		$MessageID)	// Identifikace zpravy
	{
		QX("Call MessageDownload MessageID: ".$MessageID);
		$this->NullRetInfo();
		$MessInput=array(
			'dmID'=>$MessageID);
		try
		{
			$MessageDownloadOutput=$this->OperationsWS->MessageDownload($MessInput);
		}
		catch (Exception $e)
		{
			$this->ErrorInfo=$e->getMessage();
			return false;
		}
		$ReturnedMessage=$MessageDownloadOutput->dmReturnedMessage;
		$MessageStatus=$MessageDownloadOutput->dmStatus;
		$this->StatusCode=$MessageStatus->dmStatusCode;
		$this->StatusMessage=$MessageStatus->dmStatusMessage;
		$ReturnedMessage->dmDm->dmFiles->dmFile=$this->PrepareArray($ReturnedMessage->dmDm->dmFiles->dmFile);
		QX("StatusCode: ".$this->StatusCode);
		QX("StatusMessage: ".$this->StatusMessage);		
		return $ReturnedMessage;		
	}

	function SignedMessageDownload(
	// Stazeni podepsane prijate zpravy. Vrati stazenou zpravu v binarnim formatu.
		$MessageID)	// Identifikace zpravy
	{
		QX("Call SignedMessageDownload MessageID: ".$MessageID);
		$this->NullRetInfo();
		$MessInput=array(
			'dmID'=>$MessageID);
		try
		{
			$SignedMessDownOutput=$this->OperationsWS->SignedMessageDownload($MessInput);
		}
		catch (Exception $e)
		{
			$this->ErrorInfo=$e->getMessage();
			return false;		
		}
		$ReturnedMessage=$SignedMessDownOutput->dmSignature;
		$MessageStatus=$SignedMessDownOutput->dmStatus;
		$this->StatusCode=$MessageStatus->dmStatusCode;
		$this->StatusMessage=$MessageStatus->dmStatusMessage;
		QX("StatusCode: ".$this->StatusCode);
		QX("StatusMessage: ".$this->StatusMessage);		
		return $ReturnedMessage;		
	}

	function SignedMessageDownloadToFile(
	// Stazeni podepsane prijate zpravy do souboru.
		$MessageID,	// Identifikace zpravy
		$FileName)	// Jmeno souboru, do ktereho bude zprava ulozena
	{
		$Message=$this->SignedMessageDownload($MessageID);
		if (($this->StatusCode == "0000") && ($this->ErrorInfo == ""))
		{
			if (file_put_contents($FileName,$Message))
			{
				return true;
			}
		}
		return false;
	}

	function SignedSentMessageDownload(
	// Stazeni podepsane odeslane zpravy. Vrati stazenou zpravu v binarnim formatu.
		$MessageID) // Identifikace zpravy
	{
		QX("Call SignedSentMessageDownload MessageID: ".$MessageID);
		$this->NullRetInfo();
		$MessInput=array(
			'dmID'=>$MessageID);
		try
		{
			$SignedMessDownOutput=$this->OperationsWS->SignedSentMessageDownload($MessInput);
		}
		catch (Exception $e)
		{
			$this->ErrorInfo=$e->getMessage();
			return false;		
		}
		$ReturnedMessage=$SignedMessDownOutput->dmSignature;
		$MessageStatus=$SignedMessDownOutput->dmStatus;
		$this->StatusCode=$MessageStatus->dmStatusCode;
		$this->StatusMessage=$MessageStatus->dmStatusMessage;
		QX("StatusCode: ".$this->StatusCode);
		QX("StatusMessage: ".$this->StatusMessage);		
		return $ReturnedMessage;		
	}

	function SignedSentMessageDownloadToFile(
	// Stazeni podepsane odeslane zpravy do souboru
		$MessageID,	// Identifikace zpravy
		$FileName)	// Jmeno souboru, do ktereho bude zprava ulozena
	{
		$Message=$this->SignedSentMessageDownload($MessageID);
		if (($this->StatusCode == "0000") && ($this->ErrorInfo == ""))
		{
			if (file_put_contents($FileName,$Message))
			{
				return true;
			}
		}
		return false;
	}

	function DummyOperation()
	{
		$this->OperationsWS->DummyOperation();
	}	

	function VerifyMessage(
	// Slouzi k porovnani hashe datove zpravy ulozene mimo ISDS s originalem. Vrati hash zpravy
		$MessageID)	// Identifikace zpravy
	{
		QX("Call VerifyMessage MessageID: ".$MessageID);
		$this->NullRetInfo();
		$MessInput=array(
			'dmID'=>$MessageID);
		try
		{
			$MessageVerifyOutput=$this->InfoWS->VerifyMessage($MessInput);
		}
		catch (Exception $e)
		{
			$this->ErrorInfo=$e->getMessage();
			return false;		
		}
		$Status=$MessageVerifyOutput->dmStatus;
		$this->StatusCode=$Status->dmStatusCode;
		$this->StatusMessage=$Status->dmStatusMessage;
		QX("StatusCode: ".$this->StatusCode);
		QX("StatusMessage: ".$this->StatusMessage);		
		return $MessageVerifyOutput->dmHash;
	}

	function MessageEnvelopeDownload(
	// Stazeni pouhe obalky prijate zpravy (bez pisemnosti). Vrati obalku zpravy.
		$MessageID)	// Identifikace zpravy
	{
		QX("Call MessageEnvelopeDownload MessageID: ".$MessageID);
		$this->NullRetInfo();
		$MessInput=array(
			'dmID'=>$MessageID);
		try
		{
			$MessEnvelDownOutput=$this->InfoWS->MessageEnvelopeDownload($MessInput);
		}
		catch (Exception $e)
		{
			$this->ErrorInfo=$e->getMessage();
			return false;		
		}
		$Status=$MessEnvelDownOutput->dmStatus;
		$this->StatusCode=$Status->dmStatusCode;
		$this->StatusMessage=$Status->dmStatusMessage;
		QX("StatusCode: ".$this->StatusCode);
		QX("StatusMessage: ".$this->StatusMessage);		
		return $MessEnvelDownOutput->dmReturnedMessageEnvelope;
	}

	function MarkMessageAsDownloaded(
	// Vybrana dorucena zprava bude oznacena jako prectena.
		$MessageID)	// Identifikace zpravy
	{
		QX("Call MarkMessageAsDownloaded MessageID: ".$MessageID);
		$this->NullRetInfo();
		$MessInput=array(
			'dmID'=>$MessageID);
		try
		{
			$MarkMessOut=$this->InfoWS->MarkMessageAsDownloaded($MessInput);
		}
		catch (Exception $e)
		{
			$this->ErrorInfo=$e->getMessage();
			return false;		
		}
		$this->StatusCode=$MarkMessOut->dmStatus->dmStatusCode;
		$this->StatusMessage=$MarkMessOut->dmStatus->dmStatusMessage;
		QX("StatusCode: ".$this->StatusCode);
		QX("StatusMessage: ".$this->StatusMessage);		
		return true;
	}

	function PrepareArray($A)
	{
		if (count($A) != 1)
		{
			return $A;
		}
		$B=array();
		$B[0]=$A;
		return $B;
	}

	function GetDeliveryInfo(
	// Stazeni informace o dodani, doruceni nebo nedoruceni zpravy. Vrati dorucenku.
		$MessageID)	// Identifikace zpravy
	{
		QX("Call GetDeliveryInfo MessageID: ".$MessageID);
		$this->NullRetInfo();
		$MessInput=array(
			'dmID'=>$MessageID);
		try
		{
			$DeliveryMessageOutput=$this->InfoWS->GetDeliveryInfo($MessInput);
		}
		catch (Exception $e)
		{
			$this->ErrorInfo=$e->getMessage();
			return false;		
		}
		$this->StatusCode=$DeliveryMessageOutput->dmStatus->dmStatusCode;
		$this->StatusMessage=$DeliveryMessageOutput->dmStatus->dmStatusMessage;
		QX("StatusCode: ".$this->StatusCode);
		QX("StatusMessage: ".$this->StatusMessage);		
		$DeliveryMessageOutput->dmDelivery->dmEvents->dmEvent=$this->PrepareArray($DeliveryMessageOutput->dmDelivery->dmEvents->dmEvent);
		return $DeliveryMessageOutput->dmDelivery;
	}

	function GetSignedDeliveryInfo(
	// Stazeni podepsane informace o dodani, doruceni nebo nedoruceni zpravy
		$MessageID)	// Identifikace zpravy
	{
		QX("Call GetSignedDeliveryInfo MessageID: ".$MessageID);
		$this->NullRetInfo();
		$MessInput=array(
			'dmID'=>$MessageID);
		try
		{
			$SignDeliveryMessOutput=$this->InfoWS->GetSignedDeliveryInfo($MessInput);
		}
		catch (Exception $e)
		{
			$this->ErrorInfo=$e->getMessage();
			return false;		
		}
		$this->StatusCode=$SignDeliveryMessOutput->dmStatus->dmStatusCode;
		$this->StatusMessage=$SignDeliveryMessOutput->dmStatus->dmStatusMessage;
		QX("StatusCode: ".$this->StatusCode);
		QX("StatusMessage: ".$this->StatusMessage);		
		return $SignDeliveryMessOutput->dmSignature;

	}

	function GetSignedDeliveryInfoToFile(
	// stazeni dorucenky v podepsanem tvaru do souboru
		$MessageID,	// Identifikace zpravy
		$FileName)	// jmeno souboru
	{
		$Delivery=$this->GetSignedDeliveryInfo($MessageID);
		if (($this->StatusCode == "0000") && ($this->ErrorInfo == ""))
		{
			if (file_put_contents($FileName,$Delivery))
			{
				return true;
			}
		}
		return false;
	}

	function GetListOfSentMessages(
		// Stazeni seznamu odeslanych zprav urceneho casovym intervalem, organizacni jednotkou odesilatele, 
		// filtrem na stav zprav a usekem poradovych cisel zaznamu. Vrati seznam zprav.
		$FromTime,	// Pocatek casoveho intervalu z nehoz maji byt zpravy nacteny
		$ToTime,	// Konec casoveho intervalu z nehoz maji byt zpravy nacteny
		$Offset,	// Cislo prvniho pozadovaneho zaznamu
		$Limit,		// Pocet pozadovanych zaznamu
		$StatusFilter,	// Filtr na stav zpravy. Je mozne specifikovat pozadovane 
			        // zpravy kombinaci nasledujicich hodnot (jde o bitove priznaky):
			        // 1 podana
				// 2 dostala razitko
				// 3 neprosla antivirem
				// 4 dodana
				// 5 dorucena fikci - tzn. uplynutim casu 10 dnu
				// 6 dorucena prihlasenim
				// 7 prectena
				// 8 nedorucitelna (znepristupnena schranka po odeslani)
				// 9 smazana
		$SenderOrgUnitNum) // Organizacni slozka odesilatele (z ciselniku)
	{
		QX("Call GetListOfSentMessages");
		$this->NullRetInfo();
		$ListOfSentInput=array(
			'dmFromTime'=>$FromTime,
			'dmToTime'=>$ToTime,
			'dmSenderOrgUnitNum'=>$SenderOrgUnitNum,
			'dmStatusFilter'=>$StatusFilter,
			'dmOffset'=>$Offset,
			'dmLimit'=>$Limit);
		try
		{
			$ListOfSentOutput=$this->InfoWS->GetListOfSentMessages($ListOfSentInput);
		}
		catch (Exception $e)
		{
			$this->ErrorInfo=$e->getMessage();
			return false;		
		}
		$this->StatusCode=$ListOfSentOutput->dmStatus->dmStatusCode;
		$this->StatusMessage=$ListOfSentOutput->dmStatus->dmStatusMessage;
		$ListOfSentOutput->dmRecords->dmRecord=$this->PrepareArray($ListOfSentOutput->dmRecords->dmRecord);
		QX("StatusCode: ".$this->StatusCode);
		QX("StatusMessage: ".$this->StatusMessage);		
		return $ListOfSentOutput->dmRecords;
	}

	function GetListOfReceivedMessages(
		// Stazeni seznamu doslych zprav urceneho casovym intervalem, 
	  // zpresnenim organizacni jednotky adresata (pouze ESS), filtrem na stav zprav 
	  // a usekem poradovych cisel zaznamu
		$FromTime,	// Pocatek casoveho intervalu z nehoz maji byt zpravy nacteny
		$ToTime,	// Konec casoveho intervalu z nehoz maji byt zpravy nacteny
		$Offset,	// Cislo prvniho pozadovaneho zaznamu
		$Limit,		// Pocet pozadovanych zaznamu
		$StatusFilter,	// Filtr na stav zpravy. Je mozne specifikovat pozadovane 
			              // zpravy kombinaci nasledujicich hodnot (jde o bitove priznaky):
			  // 1 podana
				// 2 dostala razitko
				// 3 neprosla antivirem
				// 4 dodana
				// 5 dorucena fikci - tzn. uplynutim casu 10 dnu
				// 6 dorucena prihlasenim
				// 7 prectena
				// 8 nedorucitelna (znepristupnena schranka po odeslani)
				// 9 smazana
		$RecipientOrgUnitNum)	// Organizacni slozka adresata (z ciselniku)
	{
		QX("Call GetListOfReceivedMessages");
		$this->NullRetInfo();
		$ListOfReceivedInput=array(
			'dmFromTime'=>$FromTime,
			'dmToTime'=>$ToTime,
			'dmRecipientOrgUnitNum'=>$RecipientOrgUnitNum,
			'dmStatusFilter'=>$StatusFilter,
			'dmOffset'=>$Offset,
			'dmLimit'=>$Limit);
		try
		{
			$ListOfRecOutput=$this->InfoWS->GetListOfReceivedMessages($ListOfReceivedInput);
		}
		catch (Exception $e)
		{
			$this->ErrorInfo=$e->getMessage();
			return false;		
		}
		$this->StatusCode=$ListOfRecOutput->dmStatus->dmStatusCode;
		$this->StatusMessage=$ListOfRecOutput->dmStatus->dmStatusMessage;
		$ListOfRecOutput->dmRecords->dmRecord=$this->PrepareArray($ListOfRecOutput->dmRecords->dmRecord);
		QX("StatusCode: ".$this->StatusCode);
		QX("StatusMessage: ".$this->StatusMessage);		
		return $ListOfRecOutput->dmRecords;
	}

	function FindDataBox(
		// vyhledani datove schranky
		$IdDb,
		$Type,	// typ datove schranky. Muze byt jedna z hodnot: FO,PFO,PFO_ADVOK,PFO_DANPOR,PFO_INSSPR,PO,PO_ZAK,PO_REQ,OVM,OVM_NOTAR,OVM_EXEKUT
		$dbState,  // stav schranky: 1 pristupna
		$ic,
		$FirstName,
		$MiddleName,
		$LastName,
		$LastNameAtBirth,
		$firmName,
		$biDate,
		$biCity,
		$biCounty,
		$biState,
		$adCity,
		$adStreet,
		$adNumberInStreet,
		$adNumberInMunicipality,
		$adZipCode,
		$adState,
		$nationality,
		$email,
		$telNumber)
		
	{
		QX("Call FindDataBox");
		$this->NullRetInfo();
		$OwnerInfo=array(
			'dbID'=>$IdDb,
			'dbType'=>$Type,
			'dbState'=>$dbState,
			'ic'=>$ic,
			'pnFirstName'=>$FirstName,
			'pnMiddleName'=>$MiddleName,
			'pnLastName'=>$LastName,
			'pnLastNameAtBirth'=>$LastNameAtBirth,
			'firmName'=>$firmName,
			'biDate'=>$biDate,
			'biCity'=>$biCity,
			'biCounty'=>$biCounty,
			'biState'=>$biState,
			'adCity'=>$adCity,
			'adStreet'=>$adStreet,
			'adNumberInStreet'=>$adNumberInStreet,
			'adNumberInMunicipality'=>$adNumberInMunicipality,
			'adZipCode'=>$adZipCode,
			'adState'=>$adState,
			'nationality'=>$nationality,
			'email'=>$email,
			'telNumber'=>$telNumber);
		$FindInput=array('dbOwnerInfo'=>$OwnerInfo);			
		try
		{
			$FindOutput=$this->ManipulationsWS->FindDataBox($FindInput);
		}
		catch (Exception $e)
		{
			$this->ErrorInfo=$e->getMessage();
			return false;		
		}
		$this->StatusCode=$FindOutput->dbStatus->dbStatusCode;
		$this->StatusMessage=$FindOutput->dbStatus->dbStatusMessage;
    if (isset($FindOutput->dbResults->dbOwnerInfo)) {
		  $FindOutput->dbResults->dbOwnerInfo=$this->PrepareArray($FindOutput->dbResults->dbOwnerInfo);
    }
		QX("StatusCode: ".$this->StatusCode);
		QX("StatusMessage: ".$this->StatusMessage);		
		return $FindOutput->dbResults;
	}

	function CheckDataBox(
		// overeni dostupnosti datove schranky
		// vrati:
		// 1 = schranka je pristupna
		// 2 = schranka je docasne znepristupnena a muze byt pozdeji opet zpristupnena
		// 3 = schranka je dosud neaktivni, existuje mene nez 15 dni a nikdo se do ni dosud neprihlasil
		// 4 = schranka je trvale znepristupnena a ceka 3 roky na smazani
		// 5 = schranka je smazana
		$DataBoxID) // ID schranky
	{
		QX("Call CheckDataBox");
		$this->NullRetInfo();
		$Inputdb=array('dbID'=>$DataBoxID);
		try
		{
			$Output=$this->ManipulationsWS->CheckDataBox($Inputdb);
		}
		catch (Exception $e)
		{
			$this->ErrorInfo=$e->getMessage();
			return false;		
		}
		$this->StatusCode=$Output->dbStatus->dbStatusCode;
		$this->StatusMessage=$Output->dbStatus->dbStatusMessage;
		QX("StatusCode: ".$this->StatusCode);
		QX("StatusMessage: ".$this->StatusMessage);		
		return $Output->dbState;
	}

	function GetNumOfMessages(
		// funkce vrati pole s pocty zasilek, indexovane stavem zasilek
	  // 1 podana
		// 2 dostala razitko
		// 3 neprosla antivirem
		// 4 dodana
		// 5 dorucena fikci - tzn. uplynutim casu 10 dnu
		// 6 dorucena prihlasenim
		// 7 prectena
		// 8 nedorucitelna (znepristupnena schranka po odeslani)
		// 9 smazana
		$received) // pokud true, ctou se prijate zasilky, pokud false, ctou se odeslane

	{
		QX("Call GetNumOfMessages");
		$Result=array();
		for ($i=1; $i<=9; $i++)
		{
			$Result[$i]=0;
		}
		$Step=512;
		$Start=1;
		do
		{	
			QX('Start: '.$Start);
			QX('Pocet: '.$Step);
			if ($received)
			{
				$Records=$this->GetListOfReceivedMessages('2000','3000',$Start,$Step,1023,null);
			}
			else
			{
				$Records=$this->GetListOfSentMessages('2000','3000',$Start,$Step,1023,null);
			}
			$Start=$Start+$Step;
			$NumOf=count($Records->dmRecord);
			QX('NumOf: '.$NumOf);
			for ($i=0;$i<$NumOf;$i++)
			{
				$Result[$Records->dmRecord[$i]->dmMessageStatus]++;
			}
		} while ($NumOf == $Step);
		return $Result;
	}

	function MarkAllReceivedMessagesAsDownloaded()
	// vsechny dorucene zpravy oznaci jako prectene
	{
		QX("Call MarkAllReceivedMessagesAsDownloaded");
		$Step=512;
		$Start=1;
		do
		{	
			QX('Start: '.$Start);
			QX('Pocet: '.$Step);
			$Records=$this->GetListOfReceivedMessages('2000','3000',$Start,$Step,1023,null);
			$Start=$Start+$Step;
			$NumOf=count($Records->dmRecord);
			QX('NumOf: '.$NumOf);
			for ($i=0;$i<$NumOf;$i++)
			{
				if ($Records->dmRecord[$i]->dmMessageStatus != 7)
				{
					$this->MarkMessageAsDownloaded($Records->dmRecord[$i]->dmID);
				}
			}
		} while ($NumOf == $Step);
		return true;		
	}

	function GetOwnerInfoFromLogin()
	// vrati udaje o majiteli schranky ke ktere jsme prihlaseni
	{
		QX("Call GetOwnerInfoFromLogin");
		$this->NullRetInfo();
		$Input=array('dbDummy'=>"");
		try
		{
			$Output=$this->AccessWS->GetOwnerInfoFromLogin($Input);
		}
		catch (Exception $e)
		{
			$this->ErrorInfo=$e->getMessage();
			return false;		
		}
		$this->StatusCode=$Output->dbStatus->dbStatusCode;
		$this->StatusMessage=$Output->dbStatus->dbStatusMessage;
		QX("StatusCode: ".$this->StatusCode);
		QX("StatusMessage: ".$this->StatusMessage);		
		return $Output->dbOwnerInfo;
	}

	function ConfirmDelivery($MessageID)
	// potvrzeni doruceni komercni zpravy
	{
		QX("Call ConfirmDelivery MessageID: ".$MessageID);
		$this->NullRetInfo();
		$MessInput=array(
			'dmID'=>$MessageID);
		try
		{
			$ConDerOut=$this->InfoWS->ConfirmDelivery($MessInput);
		}
		catch (Exception $e)
		{
			$this->ErrorInfo=$e->getMessage();
			return false;		
		}
		$this->StatusCode=$ConDerOut->dmStatus->dmStatusCode;
		$this->StatusMessage=$ConDerOut->dmStatus->dmStatusMessage;
		QX("StatusCode: ".$this->StatusCode);
		QX("StatusMessage: ".$this->StatusMessage);		
		return true;
	}

	function SetOpenAddressing($BoxID)
	// povoli zadane schrance prijem komercnich zprav
	{
		QX("Call SetOpenAddressing BoxID: ".$BoxID);
		$this->NullRetInfo();
		$IDDBInput=array(
			'dbID'=>$BoxID);
		try
		{			
			$ReqStatusOut=$this->ManipulationsWS->SetOpenAddressing($IDDBInput);
		}
		catch (Exception $e)
		{
			$this->ErrorInfo=$e->getMessage();
			return false;		
		}
		$this->StatusCode=$ReqStatusOut->dbStatus->dbStatusCode;
		$this->StatusMessage=$ReqStatusOut->dbStatus->dbStatusMessage;
		QX("StatusCode: ".$this->StatusCode);
		QX("StatusMessage: ".$this->StatusMessage);		
		return true;
	}

	function ClearOpenAddressing($BoxID)
	// zakaze zadane schrance prijem komercnich zprav
	{
		QX("Call ClearOpenAddressing BoxID: ".$BoxID);
		$this->NullRetInfo();
		$IDDBInput=array(
			'dbID'=>$BoxID);
		try
		{			
			$ReqStatusOut=$this->ManipulationsWS->ClearOpenAddressing($IDDBInput);
		}
		catch (Exception $e)
		{
			$this->ErrorInfo=$e->getMessage();
			return false;		
		}
		$this->StatusCode=$ReqStatusOut->dbStatus->dbStatusCode;
		$this->StatusMessage=$ReqStatusOut->dbStatus->dbStatusMessage;
		QX("StatusCode: ".$this->StatusCode);
		QX("StatusMessage: ".$this->StatusMessage);		
		return true;
	}

	function GetUserInfoFromLogin()
	// vrati informace o prihlasenem uzivateli
	{
		QX("Call GetUserInfoFromLogin");
		$this->NullRetInfo();
		$Input=array('dbDummy'=>"");		
		try
		{
			$Output=$this->AccessWS->GetUserInfoFromLogin($Input);
		}
		catch (Exception $e)
		{
			$this->ErrorInfo=$e->getMessage();
			return false;
		}
		$this->StatusCode=$Output->dbStatus->dbStatusCode;
		$this->StatusMessage=$Output->dbStatus->dbStatusMessage;
		QX("StatusCode: ".$this->StatusCode);
		QX("StatusMessage: ".$this->StatusMessage);
		return $Output->dbUserInfo;
	}

	function GetPasswordInfo()
	// vrati udaj o expiraci hesla
	{
		QX("Call GetPasswordInfo");
		$this->NullRetInfo();
		$Input=array('dbDummy'=>"");		
		try
		{
			$Output=$this->AccessWS->GetPasswordInfo($Input);
		}
		catch (Exception $e)
		{
			$this->ErrorInfo=$e->getMessage();
			return false;
		}
		$this->StatusCode=$Output->dbStatus->dbStatusCode;
		$this->StatusMessage=$Output->dbStatus->dbStatusMessage;
		QX("StatusCode: ".$this->StatusCode);
		QX("StatusMessage: ".$this->StatusMessage);
		return $Output->pswExpDate;
	}
	
	function ChangeISDSPassword($OldPassword,$NewPassword)
	// zmeni heslo prihlaseneho uzivatele
	{
		QX("Call ChangeISDSPassword");
		$this->NullRetInfo();		
		$Input=array(
			'dbOldPassword'=>$OldPassword,
			'dbNewPassword'=>$NewPassword);		
		try
		{
			$Output=$this->AccessWS->ChangeISDSPassword($Input);
		}
		catch (Exception $e)
		{
			$this->ErrorInfo=$e->getMessage();
			return false;
		}
		$this->StatusCode=$Output->dbStatus->dbStatusCode;
		$this->StatusMessage=$Output->dbStatus->dbStatusMessage;
		QX("StatusCode: ".$this->StatusCode);
		QX("StatusMessage: ".$this->StatusMessage);
		return($this->StatusCode == "0000");
	}
	
	  // overi pravost zpravy
	function AuthenticateMessage($filePath){
	$this->NullRetInfo();
	$vysledek=null;
	$fileContent = file_get_contents($filePath);
	
  if(!fileContent){
		  Tisk("Soubor se nepodarilo nacist");
			return null;
  }
  $inputAuth = array('dmMessage'=>$fileContent);
  
  try{                                    
  $vysledek = $this->OperationsWS->AuthenticateMessage($inputAuth);
  }
  catch(Exception $e){
			$this->ErrorInfo=$e->getMessage();
			return null;
  }
  
  $this->StatusCode=$vysledek->dmStatus->dmStatusCode;
	$this->StatusMessage=$vysledek->dmStatus->dmStatusMessage;
	QX("StatusCode: ".$this->StatusCode);
	QX("StatusMessage: ".$this->StatusMessage);
		
	return $vysledek->dmAuthResult;  
  }
  
  function GetMessagesStateChanges(){
  $this->NullRetInfo();
	$vysledek=null;
	
	$FromTime='2010-06-01T00:00:00';	
  $ToTime='2011-12-01T00:00:00';
	
	$inputParams = array('dmFromTime'=>$FromTime,
			'dmToTime'=>$ToTime);
			
	try{                                    
  $vysledek = $this->InfoWS->GetMessageStateChanges($inputParams);
  
  }
  catch(Exception $e){
			$this->ErrorInfo=$e->getMessage();
			return null;
  }
  
  $this->StatusCode=$vysledek->dmStatus->dmStatusCode;
	$this->StatusMessage=$vysledek->dmStatus->dmStatusMessage;
	QX("StatusCode: ".$this->StatusCode);
	QX("StatusMessage: ".$this->StatusMessage);
	
	//echo($vysledek->dmRecords->dmRecord[0]->dmID);
	
	return $vysledek->dmRecords;  
	

  		

  }
  	// vytvori strukturu UserInfo
	function CreateUserInfo(
		$pnFirstName,	// Jmeno osoby
		$pnMiddleName,	// Prostredni jmeno
		$pnLastName,	// Prijmeni
		$pnLastNameAtBirth,	// Rodne jmeno
		$adCity,	// Adresa - obec
		$adStreet,	// Adresa - ulice/cast obce
		$adNumberInStreet,	// Cislo orientacni
		$adNumberInMunicipality,// Cislo popisne
		$adZipCode,	// PSC
		$adState,	// Stat
		$biDate,	// Datum narozeni
		$userID,	//
		$userType,	//
		$userPrivils,	//
		$ic,		// IC firmy (PO), ktery vystupuje v datech z OR jako statutarni zastupce teto PO
		$firmName,	// jmeno firmy (PO), ktery vystupuje v datech z OR jako statutarni zastupce teto PO
		$caStreet,	// ulice a cisla v jednom retezci pro kontaktni adresu v CR
		$caCity,	// mesto pro kontaktni adresu v CR
		$caZipCode, // PSC pro pro kontaktni adresu v CR
    $caState  // Stat pro kontaktni adresu 
    )	

	{
		return array(
			'pnFirstName'=>$pnFirstName,
			'pnMiddleName'=>$pnMiddleName,
			'pnLastName'=>$pnLastName,
			'pnLastNameAtBirth'=>$pnLastNameAtBirth,
			'adCity'=>$adCity,
			'adStreet'=>$adStreet,
			'adNumberInStreet'=>$adNumberInStreet,
			'adNumberInMunicipality'=>$adNumberInMunicipality,
			'adZipCode'=>$adZipCode,
			'adState'=>$adState,
			'biDate'=>$biDate,
			'userID'=>$userID,
			'userType'=>$userType,
			'userPrivils'=>$userPrivils,
			'ic'=>$ic,
			'firmName'=>$firmName,
			'caStreet'=>$caStreet,
			'caCity'=>$caCity,
			'caZipCode'=>$caZipCode,
      'caState'=>$caState);
	}
  	// vytvori strukturu PrimaryUsers
	function CreatePrimaryUsers(
		$UserInfo	// Jmeno osoby
    )	
	{
		return array('UserInfo'=>$UserInfo);
	}

	// vytvori strukturu OwnerInfo pouzivanou v administracnich funkcich
	function CreateOwnerInfo(
		$dbID,		// ID schranky
		$dbType,	// Typ datove schranky
		$ic,		// Identifikacni cislo subjektu
		$pnFirstName,	// Jmeno osoby
		$pnMiddleName,	// Prostredni jmeno
		$pnLastName,	// Prijmeni
		$pnLastNameAtBirth,	// Rodne jmeno
		$firmName,	// Nazev subjektu
		$biDate,	// Datum narozeni
		$biCity,	// Misto narozeni
		$biCounty,	// Okres narozeni (je-li misto v CR)
		$biState,	// Stat narozeni (je-li jiny nez CR)
		$adCity,	// Adresa - obec
		$adStreet,	// Adresa - ulice/cast obce
		$adNumberInStreet,	// Cislo orientacni
		$adNumberInMunicipality,// Cislo popisne
		$adZipCode,	// PSC
		$adState,	// Stat
		$nationality,	// Statni obcanstvi
		$email,		// email
		$telNumber,	// telefonni cislo
    $identifier,	// externi identifkator schranky pro poskytovatele dat OVM a PO, mozna PFO
		$registryCode,	// kod externiho registru PFO
		$dbState,	// stav DS (0-?) pouze 1 = aktivní DS k doruèování, pou·ití ve FindDataBox
		$dbEffectiveOVM,// priznak, ze DS vystupuje jako OVM (§5a) 
		$dbOpenAddressing) // priznak, ze ne-OVM DS ma aktivovano volne adresovani (§18a)

	{
		return array(
			'dbID'=>$dbID,
			'dbType'=>$dbType,
			'ic'=>$ic,
			'pnFirstName'=>$pnFirstName,
			'pnMiddleName'=>$pnMiddleName,
			'pnLastName'=>$pnLastName,
			'pnLastNameAtBirth'=>$pnLastNameAtBirth,
			'firmName'=>$firmName,
			'biDate'=>$biDate,
			'biCity'=>$biCity,
			'biCounty'=>$biCounty,
			'biState'=>$biState,
			'adCity'=>$adCity,
			'adStreet'=>$adStreet,
			'adNumberInStreet'=>$adNumberInStreet,
			'adNumberInMunicipality'=>$adNumberInMunicipality,
			'adZipCode'=>$adZipCode,
			'adState'=>$adState,
			'nationality'=>$nationality,
			'email'=>$email,
			'telNumber'=>$telNumber,
			'identifier'=>$identifier,
			'registryCode'=>$registryCode,
			'dbState'=>$dbState,
			'dbEffectiveOVM'=>$dbEffectiveOVM,
			'dbOpenAddressing'=>$dbOpenAddressing);
	}
  // provede zmenu udaju o vlastnikovi schranky
	function UpdateDataBoxDescr(
		$OldOwnerInfo,	// puvodni udaje o vlastnikovi schranky
		$NewOwnerInfo)	// nove udaje o vlastnikovi schranky
	{
		QX("Call UpdateDataBoxDescr");
		$this->NullRetInfo();
		$UpdateDBInput=array(
			'dbOldOwnerInfo'=>$OldOwnerInfo,
			'dbNewOwnerInfo'=>$NewOwnerInfo);
		try
		{
			$ReqStatusOutput=$this->ManipulationsWS->UpdateDataBoxDescr($UpdateDBInput);
		}
		catch (Exception $e)
		{
			$this->ErrorInfo=$e->getMessage();
			return false;
		}
		$this->StatusCode=$ReqStatusOutput->dbStatus->dbStatusCode;
		$this->StatusMessage=$ReqStatusOutput->dbStatus->dbStatusMessage;
		QX("StatusCode: ".$this->StatusCode);
		QX("StatusMessage: ".$this->StatusMessage);
		return($this->StatusCode == "0000");
	}
  
	// vytvori datovou schranku. Vrati ID vytvorene schranky 
	function CreateDataBox(
		$OwnerInfo,          // udaje o majiteli schranky 
    $PrimaryUsers)	
	{
		QX("Call CreateDataBox");
		$this->NullRetInfo();
		$dbInput=array(
			'dbOwnerInfo'=>$OwnerInfo,
			'dbPrimaryUsers'=>$PrimaryUsers,
			'dbFormerNames'=>'',
			'dbAccessDataId'=>'',
			'dbUpperDBId'=>'',
			'dbCEOLabel'=>'');
		try
		{
			$CreateDBOutput=$this->ManipulationsWS->CreateDataBox($dbInput);
		}
		catch (Exception $e)
		{
			$this->ErrorInfo=$e->getMessage();
			return false;
		}
		$this->StatusCode=$CreateDBOutput->dbStatus->dbStatusCode;
		$this->StatusMessage=$CreateDBOutput->dbStatus->dbStatusMessage;		
		QX("StatusCode: ".$this->StatusCode);
		QX("StatusMessage: ".$this->StatusMessage);
		return $CreateDBOutput->dbID;
	}

  // provede vymaz schranky
	function DeleteDataBox(
		$dbOwnerInfo,	// udaje o vlastnikovi schranky
		$dbOwnerTerminationDate)	// Datum
	{
		QX("Call DeleteDataBox");
		$this->NullRetInfo();
		$DeleteDBInput=array(
			'dbOwnerInfo'=>$dbOwnerInfo,
			'dbOwnerTerminationDate'=>$dbOwnerTerminationDate);
		try
		{
			$ReqStatusOutput=$this->ManipulationsWS->DeleteDataBox($DeleteDBInput);
		}
		catch (Exception $e)
		{
			$this->ErrorInfo=$e->getMessage();
			return false;
		}
		$this->StatusCode=$ReqStatusOutput->dbStatus->dbStatusCode;
		$this->StatusMessage=$ReqStatusOutput->dbStatus->dbStatusMessage;
		QX("StatusCode: ".$this->StatusCode);
		QX("StatusMessage: ".$this->StatusMessage);
		return($this->StatusCode == "0000");
	}
  
}

?>