import 'dart:async';

import 'package:flutter/foundation.dart';

import '../core/api/api_exception.dart';
import '../models/lead.dart';
import '../repositories/lead_repository.dart';

/// Paging and searching over `GET /leads`.
///
/// Search is sent to the server, never applied to the loaded page. Filtering
/// twenty-five downloaded rows would look like search and behave like a lie:
/// the matching lead is usually on page nine.
class LeadListController extends ChangeNotifier {
  LeadListController({
    required LeadRepository repository,
    Duration searchDebounce = const Duration(milliseconds: 350),
  })  : _repository = repository,
        _searchDebounce = searchDebounce;

  final LeadRepository _repository;
  final Duration _searchDebounce;

  final List<Lead> _leads = <Lead>[];
  bool _loading = false;
  bool _loadingMore = false;
  bool _hasMore = false;
  bool _disposed = false;
  int _page = 0;
  String _query = '';
  String? _error;
  Timer? _debounce;

  /// Guards against a slow page-1 response for "sha" landing after the faster
  /// one for "sharma" and replacing it.
  int _requestGeneration = 0;

  List<Lead> get leads => List<Lead>.unmodifiable(_leads);
  bool get loading => _loading;
  bool get loadingMore => _loadingMore;
  bool get hasMore => _hasMore;
  String get query => _query;
  String? get error => _error;
  bool get isEmpty => !_loading && _error == null && _leads.isEmpty;

  /// Types into the search box. Debounced so a five-letter surname is one
  /// request rather than five.
  void search(String value) {
    if (value == _query) {
      return;
    }

    _query = value;
    _debounce?.cancel();
    _debounce = Timer(_searchDebounce, refresh);
  }

  /// Pull-to-refresh, and the first load.
  Future<void> refresh() async {
    _debounce?.cancel();

    final generation = ++_requestGeneration;
    _loading = true;
    _error = null;
    _notify();

    try {
      final page = await _repository.list(page: 1, query: _query);

      if (generation != _requestGeneration) {
        return;
      }

      _leads
        ..clear()
        ..addAll(page.items);
      _page = page.meta.currentPage;
      _hasMore = page.hasMore;
    } on ApiException catch (error) {
      if (generation != _requestGeneration) {
        return;
      }

      _error = error.message;
      _leads.clear();
      _hasMore = false;
    } finally {
      if (generation == _requestGeneration) {
        _loading = false;
        _notify();
      }
    }
  }

  /// Next page, appended. A failure here leaves the pages already on screen
  /// alone — losing a working list because page four timed out would be a
  /// worse outcome than a missing page four.
  Future<void> loadMore() async {
    if (_loading || _loadingMore || !_hasMore) {
      return;
    }

    final generation = _requestGeneration;
    _loadingMore = true;
    _notify();

    try {
      final page = await _repository.list(page: _page + 1, query: _query);

      if (generation != _requestGeneration) {
        return;
      }

      _leads.addAll(page.items);
      _page = page.meta.currentPage;
      _hasMore = page.hasMore;
    } on ApiException catch (error) {
      if (generation == _requestGeneration) {
        _error = error.message;
        _hasMore = false;
      }
    } finally {
      if (generation == _requestGeneration) {
        _loadingMore = false;
        _notify();
      }
    }
  }

  void _notify() {
    if (_disposed) {
      return;
    }

    notifyListeners();
  }

  @override
  void dispose() {
    _disposed = true;
    _debounce?.cancel();
    super.dispose();
  }
}
