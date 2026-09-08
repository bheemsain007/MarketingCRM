import 'package:flutter/material.dart';

import '../core/di/dependencies.dart';
import '../models/lead.dart';
import '../state/lead_list_controller.dart';
import '../theme/status_colors.dart';
import '../widgets/animations.dart';
import '../widgets/state_views.dart';
import 'lead_detail_screen.dart';

/// `GET /leads` — search, pagination, pull-to-refresh.
class LeadListScreen extends StatefulWidget {
  const LeadListScreen({super.key});

  static const Key searchFieldKey = Key('leads.search');
  static const Key listKey = Key('leads.list');

  @override
  State<LeadListScreen> createState() => _LeadListScreenState();
}

class _LeadListScreenState extends State<LeadListScreen> {
  LeadListController? _controller;
  final ScrollController _scroll = ScrollController();

  @override
  void didChangeDependencies() {
    super.didChangeDependencies();

    if (_controller == null) {
      _controller = LeadListController(repository: AppScope.of(context).leadRepository);
      _scroll.addListener(_onScroll);
      _controller!.refresh();
    }
  }

  void _onScroll() {
    if (!_scroll.hasClients) {
      return;
    }

    // Fetch the next page before the user reaches the bottom, so an ordinary
    // scroll never stops on a spinner.
    final remaining = _scroll.position.maxScrollExtent - _scroll.position.pixels;
    if (remaining < 400) {
      _controller?.loadMore();
    }
  }

  @override
  void dispose() {
    _scroll.removeListener(_onScroll);
    _scroll.dispose();
    _controller?.dispose();
    super.dispose();
  }

  @override
  Widget build(BuildContext context) {
    final controller = _controller;

    if (controller == null) {
      return const Center(child: CircularProgressIndicator());
    }

    return Column(
      children: <Widget>[
        Padding(
          padding: const EdgeInsets.fromLTRB(16, 12, 16, 8),
          child: TextField(
            key: LeadListScreen.searchFieldKey,
            onChanged: controller.search,
            textInputAction: TextInputAction.search,
            decoration: const InputDecoration(
              hintText: 'Search leads',
              prefixIcon: Icon(Icons.search),
              border: OutlineInputBorder(),
              isDense: true,
            ),
          ),
        ),
        Expanded(
          child: ListenableBuilder(
            listenable: controller,
            builder: (context, _) => _body(context, controller),
          ),
        ),
      ],
    );
  }

  Widget _body(BuildContext context, LeadListController controller) {
    if (controller.loading && controller.leads.isEmpty) {
      return const Center(child: CircularProgressIndicator());
    }

    if (controller.error != null && controller.leads.isEmpty) {
      return ErrorView(message: controller.error!, onRetry: controller.refresh);
    }

    if (controller.isEmpty) {
      return RefreshIndicator(
        onRefresh: controller.refresh,
        child: ListView(
          children: <Widget>[
            SizedBox(height: MediaQuery.sizeOf(context).height * 0.2),
            EmptyView(
              message: controller.query.isEmpty
                  ? 'No leads assigned to you yet.'
                  : 'No leads match “${controller.query}”.',
              icon: Icons.person_search_outlined,
            ),
          ],
        ),
      );
    }

    final leads = controller.leads;

    return RefreshIndicator(
      onRefresh: controller.refresh,
      child: ListView.separated(
        key: LeadListScreen.listKey,
        controller: _scroll,
        physics: const AlwaysScrollableScrollPhysics(),
        padding: const EdgeInsets.fromLTRB(12, 4, 12, 12),
        itemCount: leads.length + (controller.hasMore ? 1 : 0),
        separatorBuilder: (_, _) => const SizedBox(height: 8),
        itemBuilder: (context, index) {
          if (index >= leads.length) {
            return const Padding(
              padding: EdgeInsets.all(24),
              child: Center(child: CircularProgressIndicator()),
            );
          }

          return FadeSlideIn.staggered(
            index: index,
            child: _LeadTile(lead: leads[index], onReturn: controller.refresh),
          );
        },
      ),
    );
  }
}

class _LeadTile extends StatelessWidget {
  const _LeadTile({required this.lead, required this.onReturn});

  final Lead lead;
  final Future<void> Function() onReturn;

  @override
  Widget build(BuildContext context) {
    final (background, foreground) = StatusColors.avatar(context, lead.name);

    return Card(
      child: PressableScale(
        child: ListTile(
          leading: Hero(
            tag: 'lead-avatar-${lead.id}',
            child: CircleAvatar(
              backgroundColor: background,
              foregroundColor: foreground,
              child: Text(_initial(lead.name)),
            ),
          ),
          title: Text(lead.name, maxLines: 1, overflow: TextOverflow.ellipsis),
          subtitle: Text(lead.subtitle, maxLines: 1, overflow: TextOverflow.ellipsis),
          trailing: StatusChip(
            label: lead.statusLabel,
            colors: StatusColors.leadStatus(context, lead.status),
          ),
          onTap: () async {
            await Navigator.of(context).push(
              MaterialPageRoute<void>(builder: (_) => LeadDetailScreen(leadId: lead.id)),
            );

            // A call recorded on the detail screen changes the lead's
            // last-contacted date and can change its status, so the list is
            // stale the moment we come back.
            await onReturn();
          },
        ),
      ),
    );
  }

  static String _initial(String name) {
    final trimmed = name.trim();

    return trimmed.isEmpty ? '?' : trimmed.substring(0, 1).toUpperCase();
  }
}
