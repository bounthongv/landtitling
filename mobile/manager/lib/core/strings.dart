/// UI language. Lao is the default because the audience is Lao-speaking.
enum AppLang { lo, en }

class Strings {
  const Strings._();

  static AppLang current = AppLang.lo;

  static void setLanguage(AppLang lang) => current = lang;

  static bool get isLao => current == AppLang.lo;

  /// Look up a label by key. Lao first, English as the fallback.
  static String t(String key) {
    final lo = _lo[key];
    final en = _en[key];
    if (isLao) return lo ?? en ?? key;
    return en ?? lo ?? key;
  }

  static const Map<String, String> _lo = <String, String>{
    'appTitle': 'ບັກຊໍາລະທໍານຽມ',
    'appSubtitle': 'ເຄື່ອງມືຕິດຕາມການເກັບລາຍຮັບ',
    'signIn': 'ເຂົ້າສູ່ລະບົບ',
    'signOut': 'ອອກຈາກລະບົບ',
    'username': 'ຊື່ຜູ້ໃຊ້',
    'password': 'ລະຫັດຜ່ານ',
    'overview': 'ພາບລວມ',
    'reports': 'ລາຍງານ',
    'loading': 'ກໍາລັງໂຫຼດ...',
    'retry': 'ລອງໃຫມ່',
    'collectionRate': 'ອັດຕາການເກັບ',
    'collected': 'ເກັບໄດ້',
    'outstanding': 'ຄ້າງຈ່າຍ',
    'overdue': 'ເກີນກໍານົດ',
    'obligations': 'ພັນທະ',
    'open': 'ຍັງບໍ່ປິດ',
    'paid': 'ຈ່າຍແລ້ວ',
    'arrears': 'ໜີ້ຄ້າງ',
    'waitingVerify': 'ລໍຖ້າການກວດສອບ',
    'received': 'ໄດ້ຮັບ',
    'recent': 'ລ່າສຸດ',
    'feeYear': 'ປີຄ່າທໍານຽມ',
    'allYears': 'ທຸກປີ',
    'asOf': 'ຂໍ້ມູນ ณ',
    'scope': 'ຂອບເຂດ',
    'wholeCountry': 'ທົ່ວປະເທດ',
    'report': 'ລາຍງານ',
    'run': 'ດໍາເນີນການ',
    'downloadCsv': 'ດາວໂຫຼດ CSV',
    'shareCsv': 'ແບ່ງປັນ CSV',
    'rows': '{n} ແຖວ',
    'noRows': 'ບໍ່ມີຂໍ້ມູນ',
    'from': 'ວັນທີເລີ່ມ',
    'to': 'ວັນທີສິ້ນສຸດ',
    'all': '-- ທັງຫມົດ --',
    'province': 'ແຂວງ',
    'district': 'ເມືອງ',
    'village': 'ບ້ານ',
    'road': 'ປະເພດຖະຫນົນ',
    'period': 'ໄລຍະ',
    'month': 'ເດືອນ',
    'year': 'ປີ',
    'quarter': 'ໄຕຣມາດ',
    'half': '6 ເດືອນ',
    'range': 'ໄລຍະວັນທີ',
    'invalidCredentials': 'ຊື່ຜູ້ໃຊ້ ຫຼື ລະຫັດຜ່ານ ບໍ່ຖືກຕ້ອງ',
    'notAuthorized': 'ບໍ່ມີສິດເຂົ້າເບິ່ງ',
    'summary': 'ສະຫຼຸບ',
    'collection': 'ລາຍຮັບທີ່ເກັບໄດ້',
    'mof': 'ລາຍງານກະຊວງການເງິນ',
    'breakdownLabel': 'ລາຍລະອຽດຕາມເຂດ',
    'pickReport': 'ເລືອກລາຍງານເພື່ອເບິ່ງຂໍ້ມູນ',
  };

  static const Map<String, String> _en = <String, String>{
    'appTitle': 'Land Tax Manager',
    'appSubtitle': 'Collection monitoring tool',
    'signIn': 'Sign in',
    'signOut': 'Sign out',
    'username': 'Username',
    'password': 'Password',
    'overview': 'Overview',
    'reports': 'Reports',
    'loading': 'Loading...',
    'retry': 'Retry',
    'collectionRate': 'Collection rate',
    'collected': 'Collected',
    'outstanding': 'Outstanding',
    'overdue': 'Overdue',
    'obligations': 'Obligations',
    'open': 'Open',
    'paid': 'Paid',
    'arrears': 'Arrears',
    'waitingVerify': 'Waiting for verification',
    'received': 'Received',
    'recent': 'Recent',
    'feeYear': 'Fee year',
    'allYears': 'All years',
    'asOf': 'As of',
    'scope': 'Scope',
    'wholeCountry': 'Whole country',
    'report': 'Report',
    'run': 'Run',
    'downloadCsv': 'Download CSV',
    'shareCsv': 'Share CSV',
    'rows': '{n} rows',
    'noRows': 'No data',
    'from': 'From',
    'to': 'To',
    'all': '-- all --',
    'province': 'Province',
    'district': 'District',
    'village': 'Village',
    'road': 'Road',
    'period': 'Period',
    'month': 'Month',
    'year': 'Year',
    'quarter': 'Quarter',
    'half': '6 months',
    'range': 'Date range',
    'invalidCredentials': 'Invalid username or password',
    'notAuthorized': 'Not authorized',
    'summary': 'Summary',
    'collection': 'Collection',
    'mof': 'MOF export',
    'breakdownLabel': 'By area',
    'pickReport': 'Pick a report to see the data',
  };
}
